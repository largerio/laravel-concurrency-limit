<?php

declare(strict_types=1);

use Illuminate\Bus\Dispatcher;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Largerio\ConcurrencyLimit\Events\ConcurrencyLimitExceeded;
use Largerio\ConcurrencyLimit\Events\SlotAcquired;
use Largerio\ConcurrencyLimit\Events\SlotReleased;
use Largerio\ConcurrencyLimit\Exceptions\ConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\JobConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\Lease;
use Largerio\ConcurrencyLimit\Queue\Middleware\ConcurrencyLimited;
use Largerio\ConcurrencyLimit\Slots;
use Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs\JobRecorder;
use Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs\JobWithoutQueueInteraction;
use Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs\LimitedJob;
use Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs\LimitedListener;
use Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs\OtherLimitedJob;
use Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs\Region;
use Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs\Service;
use Largerio\ConcurrencyLimit\Tests\Fixtures\ThrowingStore;

beforeEach(function (): void {
    $this->app->singleton(JobRecorder::class);
});

/** Runs the job as a queue worker does, and returns its queue job. */
function workQueuedJob(object $command, FakeJob $job = new FakeJob): FakeJob
{
    new CallQueuedHandler(new Dispatcher(app()), app())->call($job, ['command' => serialize($command)]);

    return $job;
}

function jobRecorder(): JobRecorder
{
    return app(JobRecorder::class);
}

/**
 * Takes slots of a job pool, as other running jobs would.
 *
 * @return list<Lease>
 */
function holdJobSlots(string $limiter, string $key, int $max, int $count = 1): array
{
    $leases = [];

    foreach (range(1, $count) as $slot) {
        array_push($leases, ...slotPool()->acquire($limiter, [Slots::max($max)->by($key)]));
    }

    return $leases;
}

/** Seconds left before the first slot of the pool expires. */
function jobSlotTtl(string $limiter, string $key): int
{
    $lock = Cache::store()->getStore()->locks[slotName($limiter, $key, 1)];

    return $lock['expiresAt']->timestamp - now()->timestamp;
}

/**
 * Runs a LimitedJob again and again while the only slot of its pool `k` is
 * taken.
 *
 * @return list<int> the delays it was released with
 */
function releaseDelaysOf(ConcurrencyLimited $middleware, int $runs = 60): array
{
    holdJobSlots('job:'.LimitedJob::class, 'k', 1);

    $delays = [];

    foreach (range(1, $runs) as $run) {
        $job = workQueuedJob(new LimitedJob($middleware));

        expect($job->isReleased())->toBeTrue();

        $delays[] = $job->releaseDelay;
    }

    expect(jobRecorder()->handled)->toBe([]);

    return $delays;
}

describe('a free slot', function (): void {
    it('runs the job inside a slot, then frees the slot', function (): void {
        $held = null;
        jobRecorder()->whileHandling = function () use (&$held): void {
            $held = array_keys(Cache::store()->getStore()->locks);
        };

        $job = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k', 2)));

        expect(jobRecorder()->handled)->toHaveCount(1)
            ->and($held)->toHaveCount(1)
            ->and($held[0])->toBeIn([slotName('job:'.LimitedJob::class, 'k', 1), slotName('job:'.LimitedJob::class, 'k', 2)])
            ->and(Cache::store()->getStore()->locks)->toBe([])
            ->and($job->isReleased())->toBeFalse()
            ->and($job->isDeleted())->toBeTrue();
    });

    it('runs the job while the other slots are taken', function (): void {
        holdJobSlots('job:'.LimitedJob::class, 'k', 3, 2);

        $job = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k', 3)));

        expect(jobRecorder()->handled)->toHaveCount(1)
            ->and($job->isDeleted())->toBeTrue()
            ->and(holdJobSlots('job:'.LimitedJob::class, 'k', 3))->toHaveCount(1);
    });

    it('returns what the rest of the pipeline returns', function (): void {
        $job = new LimitedJob()->withFakeQueueInteractions();

        expect(new ConcurrencyLimited('k')->handle($job, fn (object $job): string => 'done'))->toBe('done');
    });

    it('frees the slot when the job throws', function (): void {
        Event::fake([SlotReleased::class]);
        jobRecorder()->whileHandling = fn (): never => throw new RuntimeException('The job failed.');

        expect(fn (): FakeJob => workQueuedJob(new LimitedJob(new ConcurrencyLimited('k'))))
            ->toThrow(RuntimeException::class, 'The job failed.');

        expect(Cache::store()->getStore()->locks)->toBe([]);
        Event::assertDispatchedTimes(SlotReleased::class, 1);
    });

    it('never mistakes a rejection thrown by the job for a missing slot', function (): void {
        holdJobSlots('job:'.OtherLimitedJob::class, 'k', 1);
        jobRecorder()->whileHandling = fn (): array => slotPool()->acquire('job:'.OtherLimitedJob::class, [Slots::max(1)->by('k')]);
        $job = new FakeJob;

        expect(fn (): FakeJob => workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')), $job))
            ->toThrow(ConcurrencyLimitExceededException::class);

        expect($job->isReleased())->toBeFalse()
            ->and(holdJobSlots('job:'.LimitedJob::class, 'k', 1))->toHaveCount(1);
    });

    it('resolves the slot pool for each job, after the fakes of the test', function (): void {
        $middleware = new ConcurrencyLimited('k');
        $job = new LimitedJob()->withFakeQueueInteractions();

        $middleware->handle($job, fn (): null => null);
        Event::fake([SlotAcquired::class]);
        $middleware->handle($job, fn (): null => null);

        Event::assertDispatchedTimes(SlotAcquired::class, 1);
    });
});

describe('no free slot', function (): void {
    it('releases the job after 10 to 15 seconds by default', function (): void {
        $delays = releaseDelaysOf(new ConcurrencyLimited('k'));

        expect(min($delays))->toBeGreaterThanOrEqual(10)
            ->and(max($delays))->toBeLessThanOrEqual(15)
            ->and(array_unique($delays))->not->toHaveCount(1);
    });

    it('releases the job after releaseAfter() plus up to half of it', function (): void {
        $delays = releaseDelaysOf(new ConcurrencyLimited('k')->releaseAfter(30));

        expect(min($delays))->toBeGreaterThanOrEqual(30)
            ->and(max($delays))->toBeLessThanOrEqual(45)
            ->and(array_unique($delays))->not->toHaveCount(1);
    });

    it('takes the release delay from the constructor', function (): void {
        $delays = releaseDelaysOf(new ConcurrencyLimited('k', 1, 20));

        expect(min($delays))->toBeGreaterThanOrEqual(20)
            ->and(max($delays))->toBeLessThanOrEqual(30);
    });

    it('adds up to jitter() seconds', function (): void {
        $delays = releaseDelaysOf(new ConcurrencyLimited('k')->releaseAfter(30)->jitter(2));

        expect(array_values(array_unique($delays)))->toEqualCanonicalizing([30, 31, 32]);
    });

    it('releases after exactly releaseAfter() without jitter', function (): void {
        $delays = releaseDelaysOf(new ConcurrencyLimited('k')->releaseAfter(30)->jitter(0), runs: 10);

        expect(array_unique($delays))->toBe([30]);
    });

    it('releases the job until the given date, without jitter by default', function (): void {
        $this->freezeTime();

        $delays = releaseDelaysOf(new ConcurrencyLimited('k')->releaseAfter(now()->addMinute()), runs: 10);

        expect(array_unique($delays))->toBe([60]);
    });

    it('adds an explicit jitter to a date', function (): void {
        $this->freezeTime();

        $delays = releaseDelaysOf(new ConcurrencyLimited('k')->releaseAfter(now()->addMinute())->jitter(1), runs: 30);

        expect(array_values(array_unique($delays)))->toEqualCanonicalizing([60, 61]);
    });

    it('skips the job with dontRelease(): it is deleted, not released', function (): void {
        holdJobSlots('job:'.LimitedJob::class, 'k', 1);
        Event::fake([ConcurrencyLimitExceeded::class]);

        $job = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')->dontRelease()));

        expect(jobRecorder()->handled)->toBe([])
            ->and($job->isReleased())->toBeFalse()
            ->and($job->isDeleted())->toBeTrue();
        Event::assertDispatchedTimes(ConcurrencyLimitExceeded::class, 1);
    });

    it('skips the job when the constructor is given no release delay', function (): void {
        holdJobSlots('job:'.LimitedJob::class, 'k', 1);

        $job = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k', 1, null)));

        expect($job->isReleased())->toBeFalse()
            ->and($job->isDeleted())->toBeTrue();
    });

    it('always releases a job limited to 0', function (): void {
        $job = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k', 0)));

        expect(jobRecorder()->handled)->toBe([])
            ->and($job->isReleased())->toBeTrue();
    });

    it('works when given through(), serialized with the job', function (): void {
        holdJobSlots('job:'.LimitedJob::class, 'k', 1);

        $job = workQueuedJob(new LimitedJob()->through([new ConcurrencyLimited('k')->releaseAfter(5)->jitter(0)]));

        expect($job->isReleased())->toBeTrue()
            ->and($job->releaseDelay)->toBe(5);
    });
});

describe('jobs that cannot be released', function (): void {
    it('throws to the caller of dispatchSync() rather than lose the job', function (ConcurrencyLimited $middleware): void {
        holdJobSlots('job:'.LimitedJob::class, 'k', 1);

        expect(fn (): mixed => Bus::dispatchSync(new LimitedJob($middleware)))
            ->toThrow(function (JobConcurrencyLimitExceededException $exception): void {
                expect($exception->getMessage())->toBe('The job got no concurrency slot in the [job:'.LimitedJob::class.'] pool for the key [k], and cannot be released back to a queue.')
                    ->and($exception->limiter)->toBe('job:'.LimitedJob::class)
                    ->and($exception->slots->key)->toBe('k')
                    ->and($exception->slots->max)->toBe(1)
                    ->and($exception->getPrevious())->toBeInstanceOf(ConcurrencyLimitExceededException::class);
            });

        expect(jobRecorder()->handled)->toBe([]);
    })->with([
        'releasing' => [new ConcurrencyLimited('k')],
        'not releasing' => [new ConcurrencyLimited('k')->dontRelease()],
    ]);

    it('reports a job it cannot release when no caller is left to catch the exception', function (Closure $dispatch): void {
        Exceptions::fake();
        Event::fake([ConcurrencyLimitExceeded::class, JobFailed::class]);
        config(['queue.connections.deferred' => ['driver' => 'deferred']]);
        holdJobSlots('job:'.LimitedJob::class, 'k', 1);

        $dispatch(new LimitedJob(new ConcurrencyLimited('k')));

        expect(jobRecorder()->handled)->toBe([]);
        Event::assertDispatchedTimes(ConcurrencyLimitExceeded::class, 1);
        Event::assertDispatched(JobFailed::class, fn (JobFailed $event): bool => $event->exception instanceof JobConcurrencyLimitExceededException);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (JobConcurrencyLimitExceededException $exception): bool => $exception->getPrevious() instanceof ConcurrencyLimitExceededException);
    })->with([
        'the deferred queue' => [function (LimitedJob $job): void {
            Bus::dispatch($job->onConnection('deferred'));
            app(DeferredCallbackCollection::class)->invoke();
        }],
        'dispatchAfterResponse()' => [function (LimitedJob $job): void {
            Bus::dispatchAfterResponse($job);

            // Older Laravel 13 releases let the exception escape terminate(), where the global handler reports it.
            rescue(fn () => app()->terminate());
        }],
    ]);

    it('throws for a job without InteractsWithQueue', function (): void {
        holdJobSlots('job:'.JobWithoutQueueInteraction::class, 'k', 1);
        $job = new FakeJob;

        expect(fn (): FakeJob => workQueuedJob(new JobWithoutQueueInteraction(new ConcurrencyLimited('k')), $job))
            ->toThrow(JobConcurrencyLimitExceededException::class, 'The job got no concurrency slot in the [job:'.JobWithoutQueueInteraction::class.'] pool for the key [k], and cannot be released back to a queue.');

        expect($job->isReleased())->toBeFalse()
            ->and(jobRecorder()->handled)->toBe([]);
    });

    it('throws for a job that is not on a queue', function (): void {
        holdJobSlots('job:'.LimitedJob::class, 'k', 1);

        expect(fn (): mixed => new ConcurrencyLimited('k')->handle(new LimitedJob, fn (): null => null))
            ->toThrow(JobConcurrencyLimitExceededException::class);
    });
});

describe('pools', function (): void {
    it('gives each job class its own pool', function (): void {
        holdJobSlots('job:'.LimitedJob::class, 'k', 1);

        $limited = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')));
        $other = workQueuedJob(new OtherLimitedJob(new ConcurrencyLimited('k')));

        expect($limited->isReleased())->toBeTrue()
            ->and($other->isReleased())->toBeFalse()
            ->and(jobRecorder()->handled)->toHaveCount(1)
            ->and(jobRecorder()->handled[0])->toBeInstanceOf(OtherLimitedJob::class);
    });

    it('shares one pool across job classes with shared()', function (): void {
        holdJobSlots('job', 'k', 1);

        $limited = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')->shared()));
        $other = workQueuedJob(new OtherLimitedJob(new ConcurrencyLimited('k')->shared()));
        $unshared = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')));

        expect($limited->isReleased())->toBeTrue()
            ->and($other->isReleased())->toBeTrue()
            ->and($unshared->isReleased())->toBeFalse();
    });

    it('keeps keys apart, even when they end with digits', function (): void {
        holdJobSlots('job:'.LimitedJob::class, 'k1', 1);
        holdJobSlots('job:'.LimitedJob::class, 'k', 11, 10);

        $job = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k', 11)));

        expect($job->isReleased())->toBeFalse()
            ->and(jobRecorder()->handled)->toHaveCount(1);
    });

    it('moves the pools to another namespace with withPrefix()', function (): void {
        holdJobSlots('job:'.LimitedJob::class, 'tenant-1:k', 1);
        holdJobSlots('job', 'tenant-1:k', 1);

        expect(workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')->withPrefix('tenant-1:')))->isReleased())->toBeTrue()
            ->and(workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')->withPrefix('tenant-1:')->shared()))->isReleased())->toBeTrue()
            ->and(workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')->withPrefix('tenant-2:')))->isReleased())->toBeFalse()
            ->and(workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')))->isReleased())->toBeFalse();
    });

    it('counts a queued listener against the listener class', function (): void {
        Event::fake([SlotAcquired::class]);
        holdJobSlots('job:'.CallQueuedListener::class, 'k', 1);

        $listener = new CallQueuedListener(LimitedListener::class, 'handle', [new stdClass]);
        $job = workQueuedJob($listener->through([new ConcurrencyLimited('k')]));

        expect($job->isReleased())->toBeFalse()
            ->and(jobRecorder()->handled)->toHaveCount(1);
        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->limiter === 'job:'.LimitedListener::class);
    });

    it('names the limiter and the key of the pool', function (ConcurrencyLimited $middleware, string $limiter, string $key): void {
        Event::fake([SlotAcquired::class]);

        workQueuedJob(new LimitedJob($middleware));

        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->limiter === $limiter && $event->key === $key);
    })->with([
        'per job class' => [new ConcurrencyLimited('stripe', 5), 'job:'.LimitedJob::class, 'stripe'],
        'no key' => [new ConcurrencyLimited, 'job:'.LimitedJob::class, ''],
        'backed enum' => [new ConcurrencyLimited(Service::OpenAi, 5), 'job:'.LimitedJob::class, 'openai'],
        'pure enum' => [new ConcurrencyLimited(Region::Europe, 5), 'job:'.LimitedJob::class, 'Europe'],
        'shared' => [new ConcurrencyLimited('stripe', 5)->shared(), 'job', 'stripe'],
        'prefixed' => [new ConcurrencyLimited('stripe', 5)->withPrefix('prefix:'), 'job:'.LimitedJob::class, 'prefix:stripe'],
        'prefixed and shared' => [new ConcurrencyLimited('stripe', 5)->withPrefix('prefix:')->shared(), 'job', 'prefix:stripe'],
    ]);
});

describe('slot expiry', function (): void {
    it('keeps the slot for the job timeout plus 10 seconds', function (): void {
        $this->freezeTime();
        $ttl = null;
        jobRecorder()->whileHandling = function () use (&$ttl): void {
            $ttl = jobSlotTtl('job:'.LimitedJob::class, 'k');
        };

        $job = new LimitedJob(new ConcurrencyLimited('k'));
        $job->timeout = 20;
        Bus::dispatchSync($job);

        expect($ttl)->toBe(30);
    });

    it('keeps the slot for 70 seconds when the job has no timeout', function (?int $timeout): void {
        $this->freezeTime();
        $ttl = null;
        jobRecorder()->whileHandling = function () use (&$ttl): void {
            $ttl = jobSlotTtl('job:'.LimitedJob::class, 'k');
        };

        $job = new LimitedJob(new ConcurrencyLimited('k'));
        $job->timeout = $timeout;
        Bus::dispatchSync($job);

        expect($ttl)->toBe(70);
    })->with([
        'no timeout' => [null],
        'a timeout of 0, which disables it' => [0],
    ]);

    it('keeps the slot for 70 seconds when the job cannot tell its timeout', function (): void {
        $this->freezeTime();
        $ttl = null;
        jobRecorder()->whileHandling = function () use (&$ttl): void {
            $ttl = jobSlotTtl('job:'.JobWithoutQueueInteraction::class, 'k');
        };

        workQueuedJob(new JobWithoutQueueInteraction(new ConcurrencyLimited('k')));

        expect($ttl)->toBe(70);
    });

    it('keeps the slot for expireAfter() seconds', function (string $given, int $expected): void {
        $this->freezeTime();
        $ttl = null;
        jobRecorder()->whileHandling = function () use (&$ttl): void {
            $ttl = jobSlotTtl('job:'.LimitedJob::class, 'k');
        };

        $job = new LimitedJob(match ($given) {
            'method' => new ConcurrencyLimited('k')->expireAfter(5),
            'constructor' => new ConcurrencyLimited('k', 1, 10, 15),
            'date' => new ConcurrencyLimited('k')->expireAfter(now()->addMinutes(2)),
        });
        $job->timeout = 20;
        Bus::dispatchSync($job);

        expect($ttl)->toBe($expected);
    })->with([
        'method' => ['method', 5],
        'constructor' => ['constructor', 15],
        'date' => ['date', 120],
    ]);
});

describe('events', function (): void {
    it('announces the slot acquired and released under job:{class}', function (): void {
        Event::fake([SlotAcquired::class, SlotReleased::class, ConcurrencyLimitExceeded::class]);

        workQueuedJob(new LimitedJob(new ConcurrencyLimited('stripe', 3)));

        Event::assertDispatchedTimes(SlotAcquired::class, 1);
        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->limiter === 'job:'.LimitedJob::class
            && $event->key === 'stripe'
            && $event->max === 3
            && $event->waited >= 0.0
            && $event->waited < 1.0);
        Event::assertDispatchedTimes(SlotReleased::class, 1);
        Event::assertDispatched(SlotReleased::class, fn (SlotReleased $event): bool => $event->limiter === 'job:'.LimitedJob::class
            && $event->key === 'stripe'
            && $event->max === 3
            && $event->held >= 0.0
            && $event->held < 1.0);
        Event::assertNotDispatched(ConcurrencyLimitExceeded::class);
    });

    it('announces a rejection once, and no slot', function (ConcurrencyLimited $middleware, string $limiter): void {
        holdJobSlots($limiter, 'stripe', 2, 2);
        Event::fake([SlotAcquired::class, SlotReleased::class, ConcurrencyLimitExceeded::class]);

        workQueuedJob(new LimitedJob($middleware));

        Event::assertDispatchedTimes(ConcurrencyLimitExceeded::class, 1);
        Event::assertDispatched(ConcurrencyLimitExceeded::class, fn (ConcurrencyLimitExceeded $event): bool => $event->limiter === $limiter
            && $event->key === 'stripe'
            && $event->max === 2
            && $event->waited >= 0.0);
        Event::assertNotDispatched(SlotAcquired::class);
        Event::assertNotDispatched(SlotReleased::class);
    })->with([
        'released' => [new ConcurrencyLimited('stripe', 2), 'job:'.LimitedJob::class],
        'skipped' => [new ConcurrencyLimited('stripe', 2)->dontRelease(), 'job:'.LimitedJob::class],
        'shared' => [new ConcurrencyLimited('stripe', 2)->shared(), 'job'],
    ]);
});

describe('store failures', function (): void {
    it('runs the job without a slot and reports the failure once when failing open', function (): void {
        Exceptions::fake();
        Event::fake([SlotAcquired::class, SlotReleased::class, ConcurrencyLimitExceeded::class]);
        useStore(new ThrowingStore);

        $job = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')));

        expect(jobRecorder()->handled)->toHaveCount(1)
            ->and($job->isReleased())->toBeFalse()
            ->and($job->isDeleted())->toBeTrue();
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(StoreUnavailableException::class);
        Event::assertNothingDispatched();
    });

    it('releases the job with its usual delay and reports the failure once when failing closed', function (): void {
        Exceptions::fake();
        Event::fake([SlotAcquired::class, SlotReleased::class, ConcurrencyLimitExceeded::class]);
        useStore(new ThrowingStore);

        $job = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')->failClosed()));

        expect(jobRecorder()->handled)->toBe([])
            ->and($job->isReleased())->toBeTrue()
            ->and($job->releaseDelay)->toBeGreaterThanOrEqual(10)
            ->and($job->releaseDelay)->toBeLessThanOrEqual(15)
            ->and($job->hasFailed())->toBeFalse();
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(StoreUnavailableException::class);
        Event::assertNothingDispatched();
    });

    it('follows the fail_open configuration unless the middleware overrides it', function (): void {
        Exceptions::fake();
        useStore(new ThrowingStore);
        config(['concurrency-limit.fail_open' => false]);

        $closed = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')));
        $open = workQueuedJob(new LimitedJob(new ConcurrencyLimited('k')->failOpen()));

        expect($closed->isReleased())->toBeTrue()
            ->and($open->isReleased())->toBeFalse()
            ->and(jobRecorder()->handled)->toHaveCount(1);
    });

    it('throws a rejection to dispatchSync() and reports the failure once when failing closed', function (): void {
        Exceptions::fake();
        useStore(new ThrowingStore);

        expect(fn (): mixed => Bus::dispatchSync(new LimitedJob(new ConcurrencyLimited('k')->failClosed())))
            ->toThrow(function (JobConcurrencyLimitExceededException $exception): void {
                expect($exception->getPrevious())->toBeInstanceOf(StoreUnavailableException::class);
            });

        expect(jobRecorder()->handled)->toBe([]);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(StoreUnavailableException::class);
    });

    it('reports the store failure and, apart, the job of the deferred queue it cannot release when failing closed', function (): void {
        Exceptions::fake();
        useStore(new ThrowingStore);
        config(['queue.connections.deferred' => ['driver' => 'deferred']]);

        Bus::dispatch(new LimitedJob(new ConcurrencyLimited('k')->failClosed())->onConnection('deferred'));
        app(DeferredCallbackCollection::class)->invoke();

        expect(jobRecorder()->handled)->toBe([]);
        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(StoreUnavailableException::class);
        Exceptions::assertReported(fn (JobConcurrencyLimitExceededException $exception): bool => $exception->getPrevious() instanceof StoreUnavailableException);
    });
});

describe('configuration', function (): void {
    it('refuses a negative release delay', function (): void {
        expect(fn (): ConcurrencyLimited => new ConcurrencyLimited('k', 1, -1))
            ->toThrow(InvalidArgumentException::class, 'A job must be released for at least 0 seconds, got -1.')
            ->and(fn (): ConcurrencyLimited => new ConcurrencyLimited('k')->releaseAfter(-5))
            ->toThrow(InvalidArgumentException::class, 'A job must be released for at least 0 seconds, got -5.');
    });

    it('refuses a negative jitter', function (): void {
        expect(fn (): ConcurrencyLimited => new ConcurrencyLimited('k')->jitter(-1))
            ->toThrow(InvalidArgumentException::class, 'The jitter must be at least 0 seconds, got -1.');
    });

    it('refuses a slot that would not expire, as soon as it is given', function (): void {
        expect(fn (): ConcurrencyLimited => new ConcurrencyLimited('k')->expireAfter(0))
            ->toThrow(InvalidArgumentException::class, 'Slots must expire after at least 1 second, got 0.')
            ->and(fn (): ConcurrencyLimited => new ConcurrencyLimited('k')->expireAfter(-5))
            ->toThrow(InvalidArgumentException::class, 'Slots must expire after at least 1 second, got -5.')
            ->and(fn (): ConcurrencyLimited => new ConcurrencyLimited('k', 1, 10, 0))
            ->toThrow(InvalidArgumentException::class, 'Slots must expire after at least 1 second, got 0.')
            ->and(fn (): ConcurrencyLimited => new ConcurrencyLimited('k')->expireAfter(now()->subMinute()))
            ->toThrow(InvalidArgumentException::class, 'Slots must expire after at least 1 second, got 0.');
    });

    it('refuses a negative limit when the job runs', function (): void {
        expect(fn (): FakeJob => workQueuedJob(new LimitedJob(new ConcurrencyLimited('k', -1))))
            ->toThrow(InvalidArgumentException::class, 'The maximum number of slots must be at least 0, got -1.');
    });
});
