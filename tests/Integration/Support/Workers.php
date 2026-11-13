<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Integration\Support;

use Closure;
use OutOfBoundsException;
use RuntimeException;

/**
 * Starts copies of bin/worker.php, lets them boot, releases them all at the
 * same instant and collects the result each one sends. A worker that sends
 * no result, or does not exit cleanly after it, fails the test with what it
 * printed.
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
    /** @var list<WorkerProcess> */
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
        $json = json_encode($options + [
            'block' => 0.0,
            'expire_after' => 60,
            'fail_open' => null,
            'work_ms' => 0,
            'hold' => false,
            'via' => 'pool',
        ], JSON_THROW_ON_ERROR);

        for ($started = 0; $started < $count; $started++) {
            $this->workers[] = new WorkerProcess("Worker {$started}", __DIR__.'/../bin/worker.php', $json);
        }

        foreach (array_keys($this->workers) as $index) {
            $this->on($index, function (WorkerProcess $worker): void {
                $worker->waitUntilReady();
            });
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
            $worker->send("{$this->startAt}\n");
        }

        return $this;
    }

    /** @return list<Result> */
    public function results(): array
    {
        $results = [];

        foreach (array_keys($this->workers) as $index) {
            /** @var Result $result */
            $result = $this->on($index, fn (WorkerProcess $worker): array => $worker->read());
            $results[] = $result;
        }

        foreach (array_keys($this->workers) as $index) {
            $this->on($index, function (WorkerProcess $worker): void {
                $worker->expectCleanExit();
            });
        }

        $this->kill();

        return $results;
    }

    /** The time a holding worker took its slot, from inside its critical section. */
    public function heldAt(int $index): float
    {
        return $this->on($index, function (WorkerProcess $worker): float {
            $message = $worker->read();
            $held = $message['held'] ?? null;

            if (! is_float($held)) {
                $printed = json_encode($message);

                $worker->fail("did not hold a slot: {$printed}");
            }

            return $held;
        });
    }

    /** SIGKILLs every worker still running, and waits for it to exit. */
    public function kill(): void
    {
        foreach ($this->workers as $worker) {
            $worker->kill();
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

    /**
     * Runs a step on one worker; when it fails, the others are killed too.
     *
     * @template T
     *
     * @param  Closure(WorkerProcess): T  $step
     * @return T
     */
    private function on(int $index, Closure $step): mixed
    {
        $worker = $this->workers[$index] ?? throw new OutOfBoundsException("There is no worker {$index}.");

        try {
            return $step($worker);
        } catch (RuntimeException $failure) {
            $this->kill();

            throw $failure;
        }
    }
}
