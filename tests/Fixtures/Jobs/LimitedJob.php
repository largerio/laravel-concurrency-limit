<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Largerio\ConcurrencyLimit\Queue\Middleware\ConcurrencyLimited;

class LimitedJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** Copied into the payload, where the middleware reads it. */
    public ?int $timeout = null;

    public function __construct(public ?ConcurrencyLimited $limiter = null) {}

    /** @return list<ConcurrencyLimited> */
    public function middleware(): array
    {
        return $this->limiter instanceof ConcurrencyLimited ? [$this->limiter] : [];
    }

    public function handle(JobRecorder $recorder): void
    {
        $recorder->record($this);
    }
}
