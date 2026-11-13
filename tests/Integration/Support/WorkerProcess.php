<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Integration\Support;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * One PHP process of the integration suite, running a script of bin/.
 *
 * The script sends its messages on file descriptor 3, one JSON line each, so
 * that nothing else it prints can be taken for a message. Its stdout and
 * stderr go to files, attached to every failure and appended to
 * build/integration/worker-output.log whenever they are not empty.
 *
 * Once its work is done, a worker exits with 0 and leaves stdout empty: an
 * exception Laravel did not catch is rendered there. PHP itself writes its
 * errors and deprecations, a dependency's included, on stderr, which only
 * shows up in the log and in failures.
 *
 * Laravel writes a package and a service manifest when it boots. Workers keep
 * theirs in the local temporary directory, away from Testbench's skeleton in
 * vendor/: the test process writes there a service manifest that lists this
 * package's provider, workers one that does not, and every process that finds
 * the other one rewrites it. On a bind mount, such as Docker Desktop's, a
 * worker that requires the manifest while another renames a new one over it
 * fails with "Stale file handle".
 */
final class WorkerProcess
{
    private const float TIMEOUT = 30.0;

    private const string LOG = __DIR__.'/../../../build/integration/worker-output.log';

    private const string BOOTSTRAP_CACHE = 'concurrency-limit-worker-bootstrap';

    /** @var list<string> */
    private readonly array $command;

    /** @var resource */
    private $process;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $messages;

    private readonly string $stdout;

    private readonly string $stderr;

    /** What was received on file descriptor 3 and not read yet. */
    private string $buffer = '';

    /** How the process ended, once it has. */
    private ?string $ending = null;

    private bool $succeeded = false;

    private bool $closed = false;

    public function __construct(public readonly string $name, string $script, string $options)
    {
        $this->command = [PHP_BINARY, '-d', 'display_errors=stderr', $script, $options];
        $this->stdout = $this->temporaryFile();
        $this->stderr = $this->temporaryFile();

        $process = proc_open($this->command, [
            0 => ['pipe', 'r'],
            1 => ['file', $this->stdout, 'w'],
            2 => ['file', $this->stderr, 'w'],
            3 => ['pipe', 'w'],
        ], $pipes, env_vars: $this->environment());

        $stdin = $pipes[0] ?? null;
        $messages = $pipes[3] ?? null;

        if ($process === false || ! is_resource($stdin) || ! is_resource($messages)) {
            throw new RuntimeException("Could not start {$this->name}.");
        }

        stream_set_blocking($messages, false);

        $this->process = $process;
        $this->stdin = $stdin;
        $this->messages = $messages;
    }

    public function __destruct()
    {
        $this->kill();

        foreach ([$this->stdout, $this->stderr] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function send(string $line): void
    {
        // A worker that has already stopped is reported by the next read().
        @fwrite($this->stdin, $line);
        @fflush($this->stdin);
    }

    /**
     * The next message, within the timeout.
     *
     * @return array<mixed>
     */
    public function read(): array
    {
        $line = $this->nextLine(microtime(true) + self::TIMEOUT);

        try {
            $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail("sent a message that is not JSON: {$line}");
        }

        if (! is_array($message)) {
            $this->fail("sent a message that is not a JSON object: {$line}");
        }

        return $message;
    }

    public function waitUntilReady(): void
    {
        $message = $this->read();

        if ($message !== ['ready' => true]) {
            $printed = json_encode($message);

            $this->fail("sent {$printed} instead of announcing it was ready");
        }
    }

    /** After its last message, a worker exits with 0 and leaves stdout empty. */
    public function expectCleanExit(): void
    {
        if (! $this->waitForEnd(self::TIMEOUT)) {
            $timeout = self::TIMEOUT;

            $this->fail("did not exit within {$timeout} seconds");
        }

        $this->drain();

        if (! $this->succeeded) {
            $this->fail((string) $this->ending);
        }

        if ($this->contents($this->stdout) !== '') {
            $this->fail('exited with code 0 but wrote to its stdout');
        }

        if ($this->buffer !== '') {
            $this->fail('sent more messages than expected');
        }
    }

    /** Asks the process to stop, as a deploy stops a queue worker. */
    public function terminate(): void
    {
        if (! $this->hasEnded()) {
            proc_terminate($this->process, SIGTERM);
        }
    }

    /**
     * SIGKILLs the process if it still runs, waits for it to exit, and keeps
     * what it sent and did not get read.
     */
    public function kill(): void
    {
        if ($this->closed) {
            return;
        }

        if (! $this->hasEnded()) {
            proc_terminate($this->process, SIGKILL);

            $this->ending = 'was killed by the driver';
        }

        $this->drain();

        fclose($this->stdin);
        fclose($this->messages);
        proc_close($this->process);

        $this->closed = true;

        $this->log();
    }

    /** Stops the process and throws, with everything it wrote. */
    public function fail(string $problem): never
    {
        $this->kill();

        throw new RuntimeException("{$this->name} {$problem}.\n{$this->output()}");
    }

    private function nextLine(float $deadline): string
    {
        while (true) {
            $end = strpos($this->buffer, "\n");

            if ($end !== false) {
                $line = substr($this->buffer, 0, $end);
                $this->buffer = substr($this->buffer, $end + 1);

                return $line;
            }

            $this->buffer .= $this->receive($deadline);
        }
    }

    /** Waits for the next bytes on the message descriptor. */
    private function receive(float $deadline): string
    {
        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                $timeout = self::TIMEOUT;

                $this->fail("sent no message for {$timeout} seconds");
            }

            $read = [$this->messages];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;

            if (stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000)) === 0) {
                continue;
            }

            $chunk = fread($this->messages, 65_536);

            if (is_string($chunk) && $chunk !== '') {
                return $chunk;
            }

            if (feof($this->messages)) {
                $this->waitForEnd(5.0);

                $ending = $this->ending ?? 'still running';

                $this->fail("stopped before its next message ({$ending})");
            }
        }
    }

    /** Whatever the process sent and nobody waited for, without blocking. */
    private function drain(): void
    {
        $this->buffer .= (string) stream_get_contents($this->messages);
    }

    private function waitForEnd(float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;

        while (! $this->hasEnded()) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(10_000);
        }

        return true;
    }

    /**
     * proc_get_status() reports the exit code only once, on the first call
     * after the exit, so it is kept.
     */
    private function hasEnded(): bool
    {
        if ($this->closed) {
            return true;
        }

        if ($this->ending !== null) {
            return true;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            return false;
        }

        $this->succeeded = ! $status['signaled'] && $status['exitcode'] === 0;
        $this->ending = $status['signaled']
            ? "was killed by signal {$status['termsig']}"
            : "exited with code {$status['exitcode']}";

        return true;
    }

    private function output(): string
    {
        $stdout = $this->contents($this->stdout);
        $stderr = $this->contents($this->stderr);
        $output = [
            '--- stdout ---',
            $stdout === '' ? '(empty)' : $stdout,
            '--- stderr ---',
            $stderr === '' ? '(empty)' : $stderr,
        ];

        if ($this->buffer !== '') {
            $output[] = '--- messages not read ---';
            $output[] = $this->buffer;
        }

        return implode("\n", $output);
    }

    /** Keeps a trace of any output, whether the test passes or not. */
    private function log(): void
    {
        if ($this->contents($this->stdout) === '' && $this->contents($this->stderr) === '') {
            return;
        }

        $directory = dirname(self::LOG);

        if (! is_dir($directory)) {
            // Another run of the suite, in another container, may create it at the same time.
            @mkdir($directory, 0777, true);
        }

        $time = new DateTimeImmutable()->format('Y-m-d H:i:s.u');
        $version = PHP_VERSION;
        $ending = $this->ending ?? 'running';
        $command = implode(' ', array_slice($this->command, 1));
        $entry = "=== {$time} PHP {$version} {$this->name} ({$ending})\n{$command}\n{$this->output()}\n\n";

        // build/ may be a bind mount that fails now and then under load; a trace is not worth a failed test.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if (@file_put_contents(self::LOG, $entry, FILE_APPEND) !== false) {
                return;
            }

            usleep(10_000);
        }
    }

    /**
     * The environment of the test process, with the manifests Laravel writes
     * at boot out of vendor/.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $directory = sys_get_temp_dir().'/'.self::BOOTSTRAP_CACHE;

        if (! is_dir($directory)) {
            // Another test process may create it at the same time.
            @mkdir($directory, 0777, true);
        }

        return [
            ...getenv(),
            'APP_PACKAGES_CACHE' => "{$directory}/packages.php",
            'APP_SERVICES_CACHE' => "{$directory}/services.php",
        ];
    }

    private function contents(string $file): string
    {
        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'concurrency-limit-worker-');

        if ($file === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        return $file;
    }
}
