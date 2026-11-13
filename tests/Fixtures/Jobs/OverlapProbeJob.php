<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Largerio\ConcurrencyLimit\Queue\Middleware\ConcurrencyLimited;

/**
 * A job of the multi-process integration suite, at most two at once. It
 * appends one JSON line to a shared log, with timestamps taken inside its
 * slot.
 */
final class OverlapProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** Released as often as it takes. */
    public int $tries = 0;

    public int $timeout = 30;

    public function __construct(
        public readonly int $number,
        public readonly string $run,
        public readonly string $log,
        public readonly int $workMs,
    ) {}

    /**
     * A short release delay keeps the suite fast; the prefix keeps the pools
     * of two runs apart.
     *
     * @return list<ConcurrencyLimited>
     */
    public function middleware(): array
    {
        return [new ConcurrencyLimited('k', 2)->withPrefix("{$this->run}:")->releaseAfter(1)->jitter(1)];
    }

    public function handle(): void
    {
        $start = microtime(true);

        usleep($this->workMs * 1000);

        $end = microtime(true);

        $line = json_encode([
            'number' => $this->number,
            'start' => $start,
            'end' => $end,
            'attempts' => $this->attempts(),
            'pid' => getmypid(),
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->log, "{$line}\n", FILE_APPEND | LOCK_EX);
    }
}
