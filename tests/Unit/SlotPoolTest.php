<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Cache\MemcachedLock;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;
use Largerio\ConcurrencyLimit\Events\ConcurrencyLimitExceeded;
use Largerio\ConcurrencyLimit\Events\SlotAcquired;
use Largerio\ConcurrencyLimit\Events\SlotReleased;
use Largerio\ConcurrencyLimit\Exceptions\ConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\Lease;
use Largerio\ConcurrencyLimit\Slots;
use Largerio\ConcurrencyLimit\Tests\Fixtures\ThrowingAfterAcquireStore;
use Largerio\ConcurrencyLimit\Tests\Fixtures\ThrowingOnReleaseStore;
use Largerio\ConcurrencyLimit\Tests\Fixtures\ThrowingStore;

/** @return list<string> */
function heldLocks(): array
{
    $locks = array_keys(Cache::store()->getStore()->locks);
    sort($locks);

    return $locks;
}

/**
 * CarbonInterval, which Sleep records, drops a microsecond on some values
 * (62507 µs becomes 62506 µs), so sums are compared within 2 µs per sleep.
 *
 * @return list<int> the faked sleeps, in microseconds
 */
function &recordSleeps(): array
{
    $sleeps = [];

    Sleep::fake();
    Sleep::whenFakingSleep(function (CarbonInterval $duration) use (&$sleeps): void {
        $sleeps[] = (int) round($duration->totalMicroseconds);
    });

    return $sleeps;
}

describe('acquiring', function (): void {
    it('grants up to max slots, then rejects', function (): void {
        $pool = slotPool();

        foreach (range(1, 3) as $request) {
            expect($pool->acquire('exports', [Slots::max(3)->by('user:1')]))->toHaveCount(1);
        }

        $slots = Slots::max(3)->by('user:1');

        expect(fn (): array => $pool->acquire('exports', [$slots]))
            ->toThrow(function (ConcurrencyLimitExceededException $exception) use ($slots): void {
                expect($exception->slots)->toBe($slots)
                    ->and($exception->getStatusCode())->toBe(429)
                    ->and($exception->getHeaders())->toBe(['Retry-After' => 1])
                    ->and($exception->getMessage())->toBe('Too Many Concurrent Requests.');
            });
    });

    it('sends the Retry-After of the limit that refused', function (): void {
        $slots = Slots::max(0)->by('user:1')->retryAfter(30);

        expect(fn (): array => slotPool()->acquire('exports', [$slots]))
            ->toThrow(function (ConcurrencyLimitExceededException $exception): void {
                expect($exception->getHeaders())->toBe(['Retry-After' => 30]);
            });
    });

    it('returns a lease describing the slot', function (): void {
        $before = microtime(true);

        [$lease] = slotPool()->acquire('exports', [Slots::max(2)->by('user:1')]);

        expect($lease)->toBeInstanceOf(Lease::class)
            ->and($lease->limiter)->toBe('exports')
            ->and($lease->key)->toBe('user:1')
            ->and($lease->max)->toBe(2)
            ->and($lease->acquiredAt)->toBeGreaterThanOrEqual($before)
            ->and($lease->acquiredAt)->toBeLessThanOrEqual(microtime(true));
    });

    it('holds one lock per slot, named after the hashed limiter and key', function (): void {
        foreach (range(1, 3) as $request) {
            slotPool()->acquire('exports', [Slots::max(3)->by('user:1')]);
        }

        expect(heldLocks())->toBe([
            slotName('exports', 'user:1', 1),
            slotName('exports', 'user:1', 2),
            slotName('exports', 'user:1', 3),
        ])->and(heldLocks()[0])->toMatch('/^concurrency:[0-9a-f]{32}:1$/');
    });

    it('locks each slot for expireAfter seconds', function (): void {
        slotPool()->acquire('exports', [Slots::max(1)->by('user:1')->expireAfter(30)]);

        $lock = Cache::store()->getStore()->locks[slotName('exports', 'user:1', 1)];

        expect($lock['expiresAt']->timestamp)->toBe(now()->addSeconds(30)->timestamp);
    });

    it('starts the scan at a random slot', function (): void {
        $first = [];

        foreach (range(1, 40) as $user) {
            slotPool()->acquire('exports', [Slots::max(4)->by("user:{$user}")]);

            foreach (range(1, 4) as $slot) {
                if (array_key_exists(slotName('exports', "user:{$user}", $slot), Cache::store()->getStore()->locks)) {
                    $first[] = $slot;
                }
            }
        }

        expect($first)->toHaveCount(40)
            ->and(array_unique($first))->toHaveCount(4);
    });

    it('keeps limiters and keys apart, even when they end with digits', function (): void {
        $pool = slotPool();

        foreach (range(1, 11) as $request) {
            $pool->acquire('exports', [Slots::max(11)->by('a')]);
        }

        expect($pool->acquire('exports', [Slots::max(1)->by('a1')]))->toHaveCount(1)
            ->and($pool->acquire('exports1', [Slots::max(1)->by('a')]))->toHaveCount(1)
            ->and($pool->acquire('imports', [Slots::max(1)->by('a')]))->toHaveCount(1);
    });

    it('accepts any key, even one that is not valid UTF-8', function (): void {
        expect(slotPool()->acquire('exports', [Slots::max(1)->by("tenant:\xff")]))->toHaveCount(1)
            ->and(heldLocks()[0])->toMatch('/^concurrency:[0-9a-f]{32}:1$/');
    });

    it('keeps binary keys apart, even when they differ only in bytes that are not valid UTF-8', function (): void {
        $pool = slotPool();
        $network = fn (string $address): string => substr((string) inet_pton($address), 0, 8);

        $pool->acquire('api', [Slots::max(1)->by($network('2001:db8:ff00:1::1'))]);

        expect($pool->acquire('api', [Slots::max(1)->by($network('2001:db8:fe00:1::1'))]))->toHaveCount(1);
    });

    it('always rejects a limit of zero without touching the store', function (): void {
        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(0)->by('user:1')]))
            ->toThrow(ConcurrencyLimitExceededException::class);

        expect(heldLocks())->toBe([]);
    });

    it('takes nothing when there is no limit', function (): void {
        Event::fake();

        expect(slotPool()->acquire('exports', []))->toBe([]);

        Event::assertNothingDispatched();
    });

    it('requires a key on every limit', function (): void {
        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1'), Slots::max(1)]))
            ->toThrow(LogicException::class, 'Every limit of the [exports] limiter needs a key; resolve the default key before acquiring.');

        expect(heldLocks())->toBe([]);
    });

    it('refuses two limits with the same key', function (): void {
        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1'), Slots::max(2)->by('user:1')]))
            ->toThrow(LogicException::class, 'The [exports] limiter has two limits with the key [user:1].');

        expect(heldLocks())->toBe([]);
    });
});

describe('several limits', function (): void {
    it('takes a slot from every limit', function (): void {
        Event::fake();

        $leases = slotPool()->acquire('exports', [
            Slots::max(2)->by('user:1'),
            Slots::max(10)->by('team:1'),
        ]);

        expect($leases)->toHaveCount(2)
            ->and($leases[0]->key)->toBe('user:1')
            ->and($leases[0]->max)->toBe(2)
            ->and($leases[1]->key)->toBe('team:1')
            ->and($leases[1]->max)->toBe(10);

        Event::assertDispatchedTimes(SlotAcquired::class, 2);
    });

    it('gives back the slots already taken when a later limit refuses', function (): void {
        $pool = slotPool();
        $pool->acquire('exports', [Slots::max(1)->by('team:1')]);

        Event::fake();
        $pool = slotPool();

        $team = Slots::max(1)->by('team:1');

        expect(fn (): array => $pool->acquire('exports', [Slots::max(1)->by('user:1'), $team]))
            ->toThrow(function (ConcurrencyLimitExceededException $exception) use ($team): void {
                expect($exception->slots)->toBe($team);
            });

        expect(heldLocks())->toBe([slotName('exports', 'team:1', 1)]);

        Event::assertNotDispatched(SlotAcquired::class);
        Event::assertNotDispatched(SlotReleased::class);
        Event::assertDispatchedTimes(ConcurrencyLimitExceeded::class, 1);
        Event::assertDispatched(ConcurrencyLimitExceeded::class, fn (ConcurrencyLimitExceeded $event): bool => $event->key === 'team:1');
    });
});

describe('releasing', function (): void {
    it('frees the slots', function (): void {
        $pool = slotPool();
        $leases = $pool->acquire('exports', [Slots::max(1)->by('user:1'), Slots::max(1)->by('team:1')]);

        $pool->release($leases);

        expect(heldLocks())->toBe([])
            ->and($pool->acquire('exports', [Slots::max(1)->by('user:1'), Slots::max(1)->by('team:1')]))->toHaveCount(2);
    });

    it('only frees a slot it still owns', function (): void {
        $pool = slotPool();
        $expired = $pool->acquire('exports', [Slots::max(1)->by('user:1')->expireAfter(1)]);

        $this->travel(2)->seconds();
        $pool->acquire('exports', [Slots::max(1)->by('user:1')]);
        $pool->release($expired);

        expect(fn (): array => $pool->acquire('exports', [Slots::max(1)->by('user:1')]))
            ->toThrow(ConcurrencyLimitExceededException::class);
    });

    it('lets a slot that was never released expire', function (): void {
        $pool = slotPool();
        $pool->acquire('exports', [Slots::max(1)->by('user:1')->expireAfter(10)]);

        $this->travel(9)->seconds();

        expect(fn (): array => $pool->acquire('exports', [Slots::max(1)->by('user:1')]))
            ->toThrow(ConcurrencyLimitExceededException::class);

        $this->travel(2)->seconds();

        expect($pool->acquire('exports', [Slots::max(1)->by('user:1')]))->toHaveCount(1);
    });
});

describe('waiting', function (): void {
    it('does not wait by default', function (): void {
        Sleep::fake();
        slotPool()->acquire('exports', [Slots::max(1)->by('user:1')]);

        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1')]))
            ->toThrow(ConcurrencyLimitExceededException::class);

        Sleep::assertNeverSlept();
    });

    it('polls every 100 ms ± 50 % until block() seconds of sleep are spent, then rejects', function (): void {
        slotPool()->acquire('exports', [Slots::max(1)->by('user:1')]);
        $sleeps = &recordSleeps();

        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1')->block(1)]))
            ->toThrow(ConcurrencyLimitExceededException::class);

        $last = array_pop($sleeps);

        expect(array_sum($sleeps) + $last)->toEqualWithDelta(1_000_000, 2 * (count($sleeps) + 1))
            ->and(count($sleeps))->toBeGreaterThanOrEqual(6)
            ->and(min($sleeps))->toBeGreaterThanOrEqual(50_000 - 2)
            ->and(max($sleeps))->toBeLessThanOrEqual(150_000)
            ->and($last)->toBeGreaterThan(0)
            ->and($last)->toBeLessThanOrEqual(150_000);
    })->repeat(5);

    it('gets a slot freed during the wait', function (): void {
        $pool = slotPool();
        $holder = $pool->acquire('exports', [Slots::max(1)->by('user:1')]);

        Sleep::fake();
        Sleep::whenFakingSleep(fn () => $pool->release($holder));

        expect($pool->acquire('exports', [Slots::max(1)->by('user:1')->block(2)]))->toHaveCount(1);

        Sleep::assertSleptTimes(1);
    });

    it('waits as long as the smallest block() among the limits', function (): void {
        slotPool()->acquire('exports', [Slots::max(1)->by('team:1')]);
        $sleeps = &recordSleeps();

        expect(fn (): array => slotPool()->acquire('exports', [
            Slots::max(1)->by('user:1')->block(5),
            Slots::max(1)->by('team:1')->block(0.3),
        ]))->toThrow(ConcurrencyLimitExceededException::class);

        expect(array_sum($sleeps))->toEqualWithDelta(300_000, 2 * count($sleeps))
            ->and(heldLocks())->toBe([slotName('exports', 'team:1', 1)]);
    });

    it('does not wait for a limit of zero, which never grants a slot', function (): void {
        Sleep::fake();

        expect(fn (): array => slotPool()->acquire('exports', [
            Slots::max(1)->by('user:1')->block(2),
            Slots::max(0)->by('team:1')->block(2),
        ]))->toThrow(ConcurrencyLimitExceededException::class);

        Sleep::assertNeverSlept();
        expect(heldLocks())->toBe([]);
    });

    it('does not wait when one of the limits does not block', function (): void {
        slotPool()->acquire('exports', [Slots::max(1)->by('team:1')]);
        Sleep::fake();

        expect(fn (): array => slotPool()->acquire('exports', [
            Slots::max(1)->by('user:1')->block(5),
            Slots::max(1)->by('team:1'),
        ]))->toThrow(ConcurrencyLimitExceededException::class);

        Sleep::assertNeverSlept();
    });
});

describe('events', function (): void {
    it('announces each slot acquired', function (): void {
        Event::fake();

        slotPool()->acquire('exports', [Slots::max(2)->by('user:1'), Slots::max(5)->by('team:1')]);

        Event::assertDispatchedTimes(SlotAcquired::class, 2);
        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->limiter === 'exports'
            && $event->key === 'user:1'
            && $event->max === 2
            && $event->waited >= 0.0
            && $event->waited < 1.0);
        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === 'team:1' && $event->max === 5);
    });

    it('announces each slot released with the time it was held', function (): void {
        Event::fake();
        $pool = slotPool();
        $leases = $pool->acquire('exports', [Slots::max(2)->by('user:1'), Slots::max(5)->by('team:1')]);

        $pool->release($leases);

        Event::assertDispatchedTimes(SlotReleased::class, 2);
        Event::assertDispatched(SlotReleased::class, fn (SlotReleased $event): bool => $event->limiter === 'exports'
            && $event->key === 'user:1'
            && $event->max === 2
            && $event->held >= 0.0
            && $event->held < 1.0);
    });

    it('announces a rejection once, with the time spent waiting', function (): void {
        slotPool()->acquire('exports', [Slots::max(1)->by('user:1')]);
        Event::fake();
        Sleep::fake();

        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1')->block(0.5)]))
            ->toThrow(ConcurrencyLimitExceededException::class);

        Event::assertDispatchedTimes(ConcurrencyLimitExceeded::class, 1);
        Event::assertDispatched(ConcurrencyLimitExceeded::class, fn (ConcurrencyLimitExceeded $event): bool => $event->limiter === 'exports'
            && $event->key === 'user:1'
            && $event->max === 1
            && $event->waited >= 0.0);
        Event::assertNotDispatched(SlotAcquired::class);
    });

    it('never runs a listener inside the acquisition, and frees the slots when one throws', function (): void {
        Exceptions::fake();
        Event::listen(SlotAcquired::class, function (): never {
            throw new ConcurrencyLimitExceededException(Slots::max(1));
        });

        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1')->failOpen()]))
            ->toThrow(ConcurrencyLimitExceededException::class);

        expect(heldLocks())->toBe([]);
        Exceptions::assertNothingReported();
    });

    it('announces the release of every slot a throwing SlotAcquired listener was told about, and of no other', function (string $throwingKey, array $announced): void {
        $failure = new RuntimeException('Metrics are down');
        $acquired = [];
        $released = [];
        Event::listen(SlotReleased::class, function (SlotReleased $event) use (&$released): void {
            $released[] = $event->key;
        });
        Event::listen(SlotAcquired::class, function (SlotAcquired $event) use (&$acquired, $throwingKey, $failure): void {
            $acquired[] = $event->key;

            if ($event->key === $throwingKey) {
                throw $failure;
            }
        });

        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1'), Slots::max(1)->by('team:1')]))
            ->toThrow(function (RuntimeException $exception) use ($failure): void {
                expect($exception)->toBe($failure);
            });

        expect($acquired)->toBe($announced)
            ->and($released)->toBe($announced)
            ->and(heldLocks())->toBe([]);
    })->with([
        'the first slot' => ['user:1', ['user:1']],
        'the second slot' => ['team:1', ['user:1', 'team:1']],
    ]);

    it('reports a failure to give back the slots when a SlotAcquired listener throws', function (): void {
        Exceptions::fake();
        useStore(new ThrowingOnReleaseStore);
        Event::listen(SlotAcquired::class, function (): never {
            throw new RuntimeException('Metrics are down');
        });

        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1')]))
            ->toThrow(RuntimeException::class, 'Metrics are down');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => $exception->getMessage() === 'The concurrency limit store failed: Connection lost');
    });
});

describe('store failures', function (): void {
    it('reports the failure once and lets the request through when failing open', function (): void {
        Exceptions::fake();
        Event::fake();
        useStore(new ThrowingStore);

        expect(slotPool()->acquire('exports', [Slots::max(2)->by('user:1')->block(1)]))->toBe([]);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => $exception->getPrevious() instanceof RuntimeException
            && $exception->getMessage() === 'The concurrency limit store failed: Connection refused');
        Event::assertNothingDispatched();
    });

    it('reports the failure once and throws it when failing closed', function (): void {
        Exceptions::fake();
        Event::fake();
        useStore(new ThrowingStore);

        $thrown = null;

        try {
            slotPool()->acquire('exports', [Slots::max(2)->by('user:1')->failClosed()]);
        } catch (StoreUnavailableException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(StoreUnavailableException::class);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => $exception === $thrown);
        Event::assertNothingDispatched();
    });

    it('follows the fail_open configuration unless the limit overrides it', function (): void {
        Exceptions::fake();
        useStore(new ThrowingStore);
        config(['concurrency-limit.fail_open' => false]);

        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1')]))
            ->toThrow(StoreUnavailableException::class)
            ->and(slotPool()->acquire('exports', [Slots::max(1)->by('user:1')->failOpen()]))->toBe([]);
    });

    it('fails closed when any of the limits fails closed', function (): void {
        Exceptions::fake();
        useStore(new ThrowingStore);

        expect(fn (): array => slotPool()->acquire('exports', [
            Slots::max(1)->by('user:1')->failOpen(),
            Slots::max(1)->by('team:1')->failClosed(),
        ]))->toThrow(StoreUnavailableException::class);

        Exceptions::assertReportedCount(1);
    });

    it('gives back the slots already taken when the store fails', function (): void {
        Exceptions::fake();
        useStore($store = new ThrowingStore(workingLocks: 1));

        expect(slotPool()->acquire('exports', [Slots::max(1)->by('user:1'), Slots::max(1)->by('team:1')]))->toBe([])
            ->and($store->locks)->toBe([]);
    });

    it('gives back a slot the store took before failing', function (): void {
        Exceptions::fake();
        useStore($store = new ThrowingAfterAcquireStore);

        expect(slotPool()->acquire('exports', [Slots::max(2)->by('user:1')]))->toBe([])
            ->and($store->locks)->toBe([]);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => $exception->getMessage() === 'The concurrency limit store failed: Read error on connection');
    });

    it('treats a failure to give back slots after a refusal as a store failure', function (): void {
        Exceptions::fake();
        Event::fake();
        useStore(new ThrowingOnReleaseStore);
        slotPool()->acquire('exports', [Slots::max(1)->by('team:1')]);

        expect(fn (): array => slotPool()->acquire('exports', [Slots::max(1)->by('user:1'), Slots::max(1)->by('team:1')->failClosed()]))
            ->toThrow(StoreUnavailableException::class, 'The concurrency limit store failed: Connection lost');

        Exceptions::assertReportedCount(1);
        Event::assertNotDispatched(ConcurrencyLimitExceeded::class);
    });

    it('stops waiting when the store fails during the wait', function (): void {
        Exceptions::fake();
        useStore($store = new ThrowingStore(workingLocks: 2));
        $store->lock(slotName('exports', 'user:1', 1), 60)->acquire();
        Sleep::fake();

        expect(slotPool()->acquire('exports', [Slots::max(1)->by('user:1')->block(5)]))->toBe([]);

        Sleep::assertSleptTimes(1);
        Exceptions::assertReportedCount(1);
    });

    it('reports a failure to release once, carries on and announces the release', function (): void {
        Exceptions::fake();
        Event::fake();
        useStore(new ThrowingOnReleaseStore);
        $pool = slotPool();
        $leases = $pool->acquire('exports', [Slots::max(1)->by('user:1'), Slots::max(1)->by('team:1')]);

        $pool->release($leases);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => $exception->getMessage() === 'The concurrency limit store failed: Connection lost');
        Event::assertDispatchedTimes(SlotReleased::class, 2);
    });

    it('reports store failures through the exception handler, which does not report rejections', function (): void {
        $handler = app(ExceptionHandler::class);

        expect($handler->shouldReport(new StoreUnavailableException(new RuntimeException('down'))))->toBeTrue()
            ->and($handler->shouldReport(new ConcurrencyLimitExceededException(Slots::max(1))))->toBeFalse();
    });

    it('refuses a store without locks whatever the fail mode', function (bool $failOpen): void {
        Exceptions::fake();
        config(['concurrency-limit.store' => 'apc', 'cache.stores.apc' => ['driver' => 'apc']]);

        $slots = Slots::max(1)->by('user:1');
        $failOpen ? $slots->failOpen() : $slots->failClosed();

        expect(fn (): array => slotPool()->acquire('exports', [$slots]))
            ->toThrow(LogicException::class, 'Concurrency slots need a cache store that supports locks, but Illuminate\Cache\ApcStore does not implement Illuminate\Contracts\Cache\LockProvider.');

        Exceptions::assertNothingReported();
    })->with(['fail open' => true, 'fail closed' => false]);

    it('treats a refusal from an unreachable Memcached server as a store failure', function (): void {
        Exceptions::fake();
        config([
            'concurrency-limit.store' => 'memcached',
            'cache.stores.memcached.servers' => [['host' => '127.0.0.1', 'port' => 1, 'weight' => 100]],
        ]);

        expect(slotPool()->acquire('exports', [Slots::max(2)->by('user:1')]))->toBe([]);

        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => str_starts_with(
            $exception->getMessage(),
            'The concurrency limit store failed: Memcached refused a slot, result code ',
        ));
    })->skip(! extension_loaded('memcached'), 'Requires ext-memcached.');

    it('reports a release that an unreachable Memcached server could not perform, and announces it', function (): void {
        Exceptions::fake();
        Event::fake([SlotReleased::class]);
        $memcached = new Memcached;
        $memcached->addServer('127.0.0.1', 1);
        $lease = new Lease(new MemcachedLock($memcached, slotName('exports', 'user:1', 1), 60), 'exports', 'user:1', 1, microtime(true));

        slotPool()->release([$lease]);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => str_starts_with(
            $exception->getMessage(),
            'The concurrency limit store failed: Memcached could not release a slot, result code ',
        ));
        Event::assertDispatchedTimes(SlotReleased::class, 1);
    })->skip(! extension_loaded('memcached'), 'Requires ext-memcached.');

    it('reads the client of a Memcached lock from the protected property the framework keeps it in', function (): void {
        $memcached = new Memcached;
        $property = new ReflectionProperty(MemcachedLock::class, 'memcached');

        expect($property->isProtected())->toBeTrue()
            ->and($property->getValue(new MemcachedLock($memcached, 'concurrency:pool:1', 60)))->toBe($memcached);
    })->skip(! extension_loaded('memcached'), 'Requires ext-memcached.');
});
