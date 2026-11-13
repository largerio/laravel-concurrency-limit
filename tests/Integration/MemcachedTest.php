<?php

declare(strict_types=1);

use Illuminate\Cache\MemcachedStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Largerio\ConcurrencyLimit\Exceptions\ConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\Slots;
use Largerio\ConcurrencyLimit\Tests\Integration\Support\StoreUnderTest;

/** Points the test at the live Memcached server, then puts the given servers behind the `memcached` store. */
function onMemcachedServers(array $servers): string
{
    $limiter = onStore(StoreUnderTest::memcached());

    config(['cache.stores.memcached.servers' => $servers]);

    return $limiter;
}

function liveMemcachedServer(): array
{
    return StoreUnderTest::memcached()->config['cache.stores.memcached.servers'][0];
}

function deadMemcachedServer(): array
{
    return ['host' => '127.0.0.1', 'port' => 1, 'weight' => 100];
}

function behindFailover(): void
{
    config([
        'cache.stores.failover' => ['driver' => 'failover', 'stores' => ['memcached', 'array']],
        'concurrency-limit.store' => 'failover',
    ]);
}

describe('behind a failover store', function (): void {
    it('refuses a slot of a full pool, as without failover', function (): void {
        $limiter = onMemcachedServers([liveMemcachedServer()]);
        behindFailover();

        $held = slotPool()->acquire($limiter, [Slots::max(1)->by('k')->failClosed()]);

        expect($held)->toHaveCount(1)
            ->and(fn (): array => slotPool()->acquire($limiter, [Slots::max(1)->by('k')->failClosed()]))
            ->toThrow(ConcurrencyLimitExceededException::class);

        slotPool()->release($held);
        expectNoLeak($limiter, 'k', 1);
    });

    it('treats an unreachable server as a store failure, not as a full pool', function (): void {
        Exceptions::fake();
        $limiter = onMemcachedServers([deadMemcachedServer()]);
        behindFailover();

        expect(slotPool()->acquire($limiter, [Slots::max(2)->by('k')]))->toBe([]);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => str_starts_with(
            $exception->getMessage(),
            'The concurrency limit store failed: Memcached refused a slot, result code ',
        ));
    });
});

describe('with several servers', function (): void {
    it('never mistakes a dead server for a full pool, whatever slot the scan starts at', function (): void {
        Exceptions::fake();
        $live = liveMemcachedServer();
        $limiter = onMemcachedServers([$live, deadMemcachedServer()]);
        /** @var MemcachedStore $store */
        $store = Cache::store('memcached')->getStore();
        $isLive = fn (string $key, int $slot): bool => $store->getMemcached()->getServerByKey($store->getPrefix().slotName($limiter, $key, $slot))['port'] === $live['port'];

        // A key with one slot on each server.
        $key = collect(range(1, 100))->map(fn (int $candidate): string => "k{$candidate}")
            ->first(fn (string $candidate): bool => $isLive($candidate, 1) !== $isLive($candidate, 2));
        $liveSlot = $isLive($key, 1) ? 1 : 2;

        $other = Cache::store('memcached')->lock(slotName($limiter, $key, $liveSlot), 60);

        expect($other->get())->toBeTrue();

        foreach (range(1, 20) as $request) {
            expect(fn (): array => slotPool()->acquire($limiter, [Slots::max(2)->by($key)->failClosed()]))
                ->toThrow(StoreUnavailableException::class);
        }

        $other->release();
    });
});

describe('releasing', function (): void {
    it('reports a release that the server could not perform', function (): void {
        Exceptions::fake();
        $limiter = onMemcachedServers([liveMemcachedServer()]);
        $leases = slotPool()->acquire($limiter, [Slots::max(1)->by('k')->failClosed()]);

        expect($leases)->toHaveCount(1);

        $memcached = Cache::store('memcached')->getStore()->getMemcached();
        $memcached->resetServerList();
        $memcached->addServer(deadMemcachedServer()['host'], deadMemcachedServer()['port']);

        slotPool()->release($leases);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => str_starts_with(
            $exception->getMessage(),
            'The concurrency limit store failed: Memcached could not release a slot, result code ',
        ));
    });

    it('reports nothing for a slot that expired or now belongs to another owner', function (): void {
        Exceptions::fake();
        $limiter = onMemcachedServers([liveMemcachedServer()]);
        $expired = slotPool()->acquire($limiter, [Slots::max(1)->by('expired')->failClosed()]);
        $reassigned = slotPool()->acquire($limiter, [Slots::max(1)->by('reassigned')->failClosed()]);

        Cache::store('memcached')->lock(slotName($limiter, 'expired', 1))->forceRelease();
        $other = Cache::store('memcached')->lock(slotName($limiter, 'reassigned', 1), 60);
        $other->forceRelease();

        expect($other->get())->toBeTrue();

        slotPool()->release([...$expired, ...$reassigned]);

        Exceptions::assertNothingReported();
        expect($other->release())->toBeTrue();
    });
});
