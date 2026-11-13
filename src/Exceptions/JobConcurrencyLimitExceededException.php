<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Exceptions;

use Largerio\ConcurrencyLimit\Slots;
use RuntimeException;
use Throwable;

/**
 * A job that got no slot and has no queue to go back to: a sync job, a
 * deferred or background one, or a job without InteractsWithQueue. Not an
 * HTTP exception on purpose, so the exception handler reports the lost job.
 */
final class JobConcurrencyLimitExceededException extends RuntimeException
{
    /**
     * @param  string  $limiter  the pool, `job:{class}` or `job`
     * @param  Slots  $slots  the limit that refused
     * @param  Throwable  $previous  the rejection, or the store failure when failing closed
     */
    public function __construct(public readonly string $limiter, public readonly Slots $slots, Throwable $previous)
    {
        parent::__construct("The job got no concurrency slot in the [{$limiter}] pool for the key [{$slots->key}], and cannot be released back to a queue.", 0, $previous);
    }
}
