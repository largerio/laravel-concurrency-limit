<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Integration\Support;

use RuntimeException;

/**
 * Starts copies of bin/queue-worker.php, each one a `queue:work` process,
 * waits until they have booted, and stops them as a deploy would.
 */
final class QueueWorkers
{
    /** @var list<array{process: resource, stdout: resource, stderr: string}> */
    private array $workers = [];

    /**
     * @param  array{
     *     config: array<string, mixed>,
     *     connection: string,
     *     queue: string,
     *     max_time: int,
     * }  $options
     */
    public function __construct(int $count, array $options)
    {
        $command = [PHP_BINARY, __DIR__.'/../bin/queue-worker.php', json_encode($options, JSON_THROW_ON_ERROR)];

        for ($started = 0; $started < $count; $started++) {
            $id = bin2hex(random_bytes(6));
            $stderr = sys_get_temp_dir()."/concurrency-limit-queue-worker-{$id}.log";
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes);
            $stdout = $pipes[1] ?? null;

            if ($process === false || $stdout === null) {
                throw new RuntimeException('Could not start a queue worker.');
            }

            stream_set_timeout($stdout, 30);

            $this->workers[] = ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        foreach ($this->workers as $index => $worker) {
            if (fgets($worker['stdout']) !== "ready\n") {
                $this->fail("Queue worker {$index} did not boot", $worker['stderr']);
            }
        }
    }

    public function __destruct()
    {
        $this->kill();
    }

    /**
     * Sends SIGTERM, so that each worker finishes its job and exits, and
     * waits for them.
     *
     * @return list<string> what each worker wrote to stderr
     */
    public function stop(): array
    {
        foreach ($this->workers as $worker) {
            proc_terminate($worker['process'], SIGTERM);
        }

        $deadline = microtime(true) + 30;

        foreach ($this->workers as $index => $worker) {
            while (proc_get_status($worker['process'])['running']) {
                if (microtime(true) > $deadline) {
                    $this->fail("Queue worker {$index} did not stop", $worker['stderr']);
                }

                usleep(20_000);
            }
        }

        $stderr = array_map(fn (array $worker): string => (string) file_get_contents($worker['stderr']), $this->workers);

        $this->kill();

        return $stderr;
    }

    /** SIGKILLs every worker still running, and waits for it to exit. */
    public function kill(): void
    {
        foreach ($this->workers as $worker) {
            proc_terminate($worker['process'], SIGKILL);
            proc_close($worker['process']);

            if (is_file($worker['stderr'])) {
                unlink($worker['stderr']);
            }
        }

        $this->workers = [];
    }

    /**
     * The largest number of jobs inside their slot at once, from the start
     * and end timestamps they took inside it.
     *
     * @param  list<array{start: float, end: float}>  $runs
     */
    public static function maxOverlap(array $runs): int
    {
        $events = [];

        foreach ($runs as $run) {
            $events[] = [$run['start'], 1];
            $events[] = [$run['end'], -1];
        }

        usort($events, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $current = 0;
        $max = 0;

        foreach ($events as [, $change]) {
            $current += $change;
            $max = max($max, $current);
        }

        return $max;
    }

    private function fail(string $message, string $stderr): never
    {
        $output = (string) file_get_contents($stderr);

        $this->kill();

        throw new RuntimeException("{$message}:\n{$output}");
    }
}
