<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Integration\Support;

use JsonException;
use OutOfBoundsException;
use RuntimeException;

/**
 * Starts copies of bin/worker.php, lets them boot, releases them all at the
 * same instant and collects the line each one prints.
 *
 * @phpstan-type Result array{
 *     arrive: float,
 *     start: ?float,
 *     end: ?float,
 *     done: float,
 *     leased: bool,
 *     status: int,
 *     retry_after?: ?string,
 *     error: ?string,
 *     reports: list<array{class: string, message: string, previous: ?string}>,
 * }
 * @phpstan-type Via 'pool'|'http'|'stream'
 */
final class Workers
{
    /** @var list<array{process: resource, stdin: resource, stdout: resource, stderr: string}> */
    private array $workers = [];

    public private(set) float $startAt = 0.0;

    public function __destruct()
    {
        $this->kill();
    }

    /**
     * @param  array{
     *     config: array<string, mixed>,
     *     limiter: string,
     *     key: string,
     *     max: int,
     *     block?: float,
     *     expire_after?: int,
     *     fail_open?: ?bool,
     *     work_ms?: int,
     *     hold?: bool,
     *     via?: Via,
     * }  $options  via: through the SlotPool (default), the HTTP middleware, or a streamed response
     */
    public function __construct(int $count, array $options)
    {
        $command = [PHP_BINARY, __DIR__.'/../bin/worker.php', json_encode($options + [
            'block' => 0.0,
            'expire_after' => 60,
            'fail_open' => null,
            'work_ms' => 0,
            'hold' => false,
            'via' => 'pool',
        ], JSON_THROW_ON_ERROR)];

        for ($started = 0; $started < $count; $started++) {
            $stderr = (string) tempnam(sys_get_temp_dir(), 'concurrency-limit-worker-');
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes);
            $stdin = $pipes[0] ?? null;
            $stdout = $pipes[1] ?? null;

            if ($process === false || $stdin === null || $stdout === null) {
                throw new RuntimeException('Could not start a worker.');
            }

            stream_set_timeout($stdout, 30);

            $this->workers[] = ['process' => $process, 'stdin' => $stdin, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        foreach (array_keys($this->workers) as $index) {
            $this->read($index);
        }
    }

    /**
     * @param  array{config: array<string, mixed>, limiter: string, key: string, max: int, block?: float, expire_after?: int, fail_open?: ?bool, work_ms?: int, via?: Via}  $options
     * @return list<Result>
     */
    public static function run(int $count, array $options): array
    {
        return new self($count, $options)->go()->results();
    }

    /**
     * Every worker spins until the same start time, a little ahead so that
     * the last one has received it.
     */
    public function go(): self
    {
        $this->startAt = microtime(true) + 0.05;

        foreach ($this->workers as $worker) {
            fwrite($worker['stdin'], "{$this->startAt}\n");
            fflush($worker['stdin']);
        }

        return $this;
    }

    /** @return list<Result> */
    public function results(): array
    {
        $results = [];

        foreach (array_keys($this->workers) as $index) {
            /** @var Result $result */
            $result = $this->read($index);
            $results[] = $result;
        }

        $this->kill();

        return $results;
    }

    /** The time a holding worker took its slot, from inside its critical section. */
    public function heldAt(int $index): float
    {
        $line = $this->read($index);

        if (! is_float($line['held'] ?? null)) {
            $printed = json_encode($line);

            throw new RuntimeException("Worker {$index} did not hold a slot: {$printed}");
        }

        return $line['held'];
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
     * The largest number of workers inside their critical section at once,
     * from the start and end timestamps they took inside it.
     *
     * @param  list<Result>  $results
     */
    public static function maxOverlap(array $results): int
    {
        $events = [];

        foreach ($results as $result) {
            if ($result['start'] !== null && $result['end'] !== null) {
                $events[] = [$result['start'], 1];
                $events[] = [$result['end'], -1];
            }
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

    /** @return array<string, mixed> */
    private function read(int $index): array
    {
        $worker = $this->workers[$index] ?? throw new OutOfBoundsException("There is no worker {$index}.");
        $line = fgets($worker['stdout']);

        if ($line === false) {
            $stderr = (string) file_get_contents($worker['stderr']);
            $this->kill();

            throw new RuntimeException("Worker {$index} stopped without a result:\n{$stderr}");
        }

        try {
            /** @var array<string, mixed> */
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $stderr = (string) file_get_contents($worker['stderr']);
            $this->kill();
            $output = $line.stream_get_contents($worker['stdout']);

            throw new RuntimeException("Worker {$index} printed something other than a JSON line:\n{$output}\nstderr:\n{$stderr}");
        }
    }
}
