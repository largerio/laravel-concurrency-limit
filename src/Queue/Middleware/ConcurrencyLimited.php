<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Queue\Middleware;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\InteractsWithTime;
use InvalidArgumentException;
use Largerio\ConcurrencyLimit\Exceptions\ConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\SlotPool;
use Largerio\ConcurrencyLimit\Slots;
use UnitEnum;

/**
 * Lets at most `limit` jobs run at once per key. A job that finds no free
 * slot is released back to the queue, after a delay with jitter.
 */
final class ConcurrencyLimited
{
    use InteractsWithTime;

    /** A worker kills a job when its timeout is up: the slot must outlive it. */
    private const int EXPIRY_MARGIN = 10;

    /** The default `--timeout` of `queue:work`, for a job that sets none. */
    private const int WORKER_TIMEOUT = 60;

    private string $key;

    /** Null skips the job instead of releasing it. */
    private DateTimeInterface|int|null $releaseAfter = null;

    /** Null derives the slot TTL from the job timeout. */
    private ?int $expiresAfter = null;

    /** Null is half of an integer `releaseAfter`, and none for a date. */
    private ?int $jitter = null;

    private string $prefix = '';

    private bool $shared = false;

    /** Null follows the `concurrency-limit.fail_open` configuration. */
    private ?bool $failOpen = null;

    public function __construct(
        UnitEnum|string $key = '',
        private readonly int $limit = 1,
        DateTimeInterface|int|null $releaseAfter = 10,
        DateTimeInterface|int|null $expiresAfter = null,
    ) {
        $this->key = match (true) {
            $key instanceof BackedEnum => (string) $key->value,
            $key instanceof UnitEnum => $key->name,
            default => $key,
        };

        if ($releaseAfter !== null) {
            $this->releaseAfter($releaseAfter);
        }

        if ($expiresAfter !== null) {
            $this->expireAfter($expiresAfter);
        }
    }

    /**
     * @param  Closure(object): mixed  $next
     *
     * @throws ConcurrencyLimitExceededException when no slot is free and the job has no queue to go back to
     */
    public function handle(object $job, Closure $next): mixed
    {
        $pool = Container::getInstance()->make(SlotPool::class);
        $queueJob = $this->queueJobOf($job);
        $slots = $this->slotsFor($queueJob);

        try {
            $leases = $pool->acquire($this->limiterFor($job), [$slots]);
        } catch (ConcurrencyLimitExceededException $exception) {
            $this->postpone($queueJob, $exception);

            return null;
        } catch (StoreUnavailableException) {
            // Failing closed. The pool has reported the failure: rethrowing
            // it would report it twice and burn an attempt.
            $this->postpone($queueJob, new ConcurrencyLimitExceededException($slots));

            return null;
        }

        try {
            return $next($job);
        } finally {
            $pool->release($leases);
        }
    }

    public function releaseAfter(DateTimeInterface|int $releaseAfter): self
    {
        if (is_int($releaseAfter) && $releaseAfter < 0) {
            throw new InvalidArgumentException("A job must be released for at least 0 seconds, got {$releaseAfter}.");
        }

        $this->releaseAfter = $releaseAfter;

        return $this;
    }

    public function dontRelease(): self
    {
        $this->releaseAfter = null;

        return $this;
    }

    public function expireAfter(DateTimeInterface|int $expiresAfter): self
    {
        $this->expiresAfter = $this->secondsUntil($expiresAfter);

        return $this;
    }

    public function jitter(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException("The jitter must be at least 0 seconds, got {$seconds}.");
        }

        $this->jitter = $seconds;

        return $this;
    }

    public function shared(): self
    {
        $this->shared = true;

        return $this;
    }

    public function withPrefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function failOpen(): self
    {
        $this->failOpen = true;

        return $this;
    }

    public function failClosed(): self
    {
        $this->failOpen = false;

        return $this;
    }

    /**
     * Releases the job, or skips it with dontRelease(). A job that has no
     * queue to go back to would be lost either way, so it gets the exception.
     *
     * @throws ConcurrencyLimitExceededException
     */
    private function postpone(?Job $queueJob, ConcurrencyLimitExceededException $exception): void
    {
        if (! $queueJob instanceof Job || $queueJob instanceof SyncJob) {
            throw $exception;
        }

        if ($this->releaseAfter === null) {
            return;
        }

        $jitter = $this->jitter ?? (is_int($this->releaseAfter) ? intdiv($this->releaseAfter, 2) : 0);

        $queueJob->release($this->secondsUntil($this->releaseAfter) + random_int(0, $jitter));
    }

    /** The queue job, as CallQueuedHandler hands it to jobs that use InteractsWithQueue. */
    private function queueJobOf(object $job): ?Job
    {
        if (! isset(class_uses_recursive($job)[InteractsWithQueue::class]) || ! property_exists($job, 'job')) {
            return null;
        }

        return $job->job instanceof Job ? $job->job : null;
    }

    private function slotsFor(?Job $queueJob): Slots
    {
        $slots = Slots::max($this->limit)
            ->by("{$this->prefix}{$this->key}")
            ->expireAfter($this->expiresAfter ?? $this->timeoutOf($queueJob) + self::EXPIRY_MARGIN);

        return match ($this->failOpen) {
            true => $slots->failOpen(),
            false => $slots->failClosed(),
            null => $slots,
        };
    }

    /** A timeout of 0 lets the job run for ever, so it counts as none. */
    private function timeoutOf(?Job $queueJob): int
    {
        $timeout = $queueJob?->timeout();

        if ($timeout === null || $timeout < 1) {
            return self::WORKER_TIMEOUT;
        }

        return $timeout;
    }

    /** Wrapped jobs (queued listeners, mailables, notifications) count as the class they wrap. */
    private function limiterFor(object $job): string
    {
        if ($this->shared) {
            return 'job';
        }

        $displayName = method_exists($job, 'displayName') ? $job->displayName() : null;
        $class = is_string($displayName) ? $displayName : $job::class;

        return "job:{$class}";
    }
}
