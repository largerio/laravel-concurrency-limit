<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Integration\Support;

use Closure;
use RuntimeException;

/**
 * Starts copies of bin/queue-worker.php, each one a `queue:work` process,
 * waits until they have booted, and stops them as a deploy would. A worker
 * that does not boot, or does not exit cleanly once stopped, fails the test
 * with what it printed.
 */
final class QueueWorkers
{
    /** @var list<WorkerProcess> */
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
        $json = json_encode($options, JSON_THROW_ON_ERROR);

        for ($started = 0; $started < $count; $started++) {
            $this->workers[] = new WorkerProcess("Queue worker {$started}", __DIR__.'/../bin/queue-worker.php', $json);
        }

        foreach ($this->workers as $worker) {
            $this->on($worker, function (WorkerProcess $worker): void {
                $worker->waitUntilReady();
            });
        }
    }

    public function __destruct()
    {
        $this->kill();
    }

    /**
     * Sends SIGTERM, so that each worker finishes its job and exits, and
     * waits for them: each one exits with 0 and leaves stdout empty.
     */
    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            $worker->terminate();
        }

        foreach ($this->workers as $worker) {
            $this->on($worker, function (WorkerProcess $worker): void {
                $worker->expectCleanExit();
            });
        }

        $this->kill();
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

    /**
     * Runs a step on one worker; when it fails, the others are killed too.
     *
     * @param  Closure(WorkerProcess): void  $step
     */
    private function on(WorkerProcess $worker, Closure $step): void
    {
        try {
            $step($worker);
        } catch (RuntimeException $failure) {
            $this->kill();

            throw $failure;
        }
    }
}
