<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Largerio\ConcurrencyLimit\Queue\Middleware\ConcurrencyLimited;

/** A queued job without InteractsWithQueue: it cannot release itself. */
final readonly class JobWithoutQueueInteraction implements ShouldQueue
{
    public function __construct(public ConcurrencyLimited $limiter) {}

    /** @return list<ConcurrencyLimited> */
    public function middleware(): array
    {
        return [$this->limiter];
    }

    public function handle(JobRecorder $recorder): void
    {
        $recorder->record($this);
    }
}
