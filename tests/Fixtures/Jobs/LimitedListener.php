<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs;

/** A queued listener: the queue runs it wrapped in a CallQueuedListener. */
final readonly class LimitedListener
{
    public function __construct(private JobRecorder $recorder) {}

    public function handle(object $event): void
    {
        $this->recorder->record($event);
    }
}
