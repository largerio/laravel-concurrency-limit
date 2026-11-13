<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit;

use Illuminate\Cache\MemcachedLock;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Sleep;
use Largerio\ConcurrencyLimit\Events\ConcurrencyLimitExceeded;
use Largerio\ConcurrencyLimit\Events\SlotAcquired;
use Largerio\ConcurrencyLimit\Events\SlotReleased;
use Largerio\ConcurrencyLimit\Exceptions\ConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use LogicException;
use Memcached;
use RuntimeException;
use Throwable;

/**
 * Holds concurrency slots as cache locks, one lock per slot, and is the only
 * class that touches the cache.
 *
 * @internal
 */
final readonly class SlotPool
{
    /** While waiting, poll every 100 ms ± 50 %. */
    private const int MIN_POLL_MICROSECONDS = 50_000;

    private const int MAX_POLL_MICROSECONDS = 150_000;

    public function __construct(
        private CacheFactory $cache,
        private Config $config,
        private Dispatcher $events,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * Takes one slot from every limit, or none.
     *
     * @param  list<Slots>  $limits
     * @return list<Lease> empty when the store failed and the limits fail open
     *
     * @throws ConcurrencyLimitExceededException
     * @throws StoreUnavailableException
     */
    public function acquire(string $limiter, array $limits): array
    {
        if ($limits === []) {
            return [];
        }

        $this->ensureEachLimitHasItsOwnKey($limiter, $limits);

        $store = $this->store();
        $startedAt = microtime(true);

        try {
            $result = $this->acquireWithin($store, $limiter, $limits, $this->waitBudget($limits));
        } catch (StoreUnavailableException $exception) {
            $this->exceptions->report($exception);

            if ($this->failsOpen($limits)) {
                return [];
            }

            throw $exception;
        }

        $waited = microtime(true) - $startedAt;

        if ($result instanceof Slots) {
            $this->events->dispatch(new ConcurrencyLimitExceeded($limiter, $this->keyOf($limiter, $result), $result->max, $waited));

            throw new ConcurrencyLimitExceededException($result);
        }

        $announced = [];

        try {
            foreach ($result as $lease) {
                $announced[] = $lease;

                $this->events->dispatch(new SlotAcquired($limiter, $lease->key, $lease->max, $waited));
            }
        } catch (Throwable $exception) {
            // Every slot goes back; only those a listener was told about are announced.
            $this->reportFailureToGiveBack($result);
            $this->announceReleased($announced);

            throw $exception;
        }

        return $result;
    }

    /**
     * Never throws on a store failure: it is reported, and the slots expire.
     *
     * @param  list<Lease>  $leases
     */
    public function release(array $leases): void
    {
        $this->reportFailureToGiveBack($leases);
        $this->announceReleased($leases);
    }

    /**
     * The wait budget is the sleep time requested, not a clock, so the loop
     * ends after a bounded number of attempts whatever the clock does.
     *
     * @param  non-empty-list<Slots>  $limits
     * @return list<Lease>|Slots the leases, or the limit that refused
     *
     * @throws StoreUnavailableException
     */
    private function acquireWithin(LockProvider $store, string $limiter, array $limits, int $budget): array|Slots
    {
        $slept = 0;

        while (true) {
            $result = $this->attempt($store, $limiter, $limits);

            if (is_array($result)) {
                return $result;
            }

            if ($slept >= $budget) {
                return $result;
            }

            $sleep = min(random_int(self::MIN_POLL_MICROSECONDS, self::MAX_POLL_MICROSECONDS), $budget - $slept);

            Sleep::usleep($sleep);

            $slept += $sleep;
        }
    }

    /**
     * @param  non-empty-list<Slots>  $limits
     * @return list<Lease>|Slots
     *
     * @throws StoreUnavailableException
     */
    private function attempt(LockProvider $store, string $limiter, array $limits): array|Slots
    {
        $leases = [];

        try {
            foreach ($limits as $slots) {
                $lease = $this->acquireSlot($store, $limiter, $slots);

                if (! $lease instanceof Lease) {
                    $this->giveBackOrFail($leases);

                    return $slots;
                }

                $leases[] = $lease;
            }

            return $leases;
        } catch (Throwable $exception) {
            $this->giveBack($leases);

            throw new StoreUnavailableException($exception);
        }
    }

    private function acquireSlot(LockProvider $store, string $limiter, Slots $slots): ?Lease
    {
        if ($slots->max === 0) {
            return null;
        }

        $key = $this->keyOf($limiter, $slots);
        $pool = hash('xxh128', serialize([$limiter, $key]));
        $start = random_int(0, $slots->max - 1);

        for ($offset = 0; $offset < $slots->max; $offset++) {
            $slot = ($start + $offset) % $slots->max + 1;
            $lock = $store->lock("concurrency:{$pool}:{$slot}", $slots->expireAfter);

            if ($this->take($lock)) {
                return new Lease($lock, $limiter, $key, $slots->max, microtime(true));
            }
        }

        return null;
    }

    private function take(Lock $lock): bool
    {
        try {
            $taken = (bool) $lock->get();
        } catch (Throwable $exception) {
            // The store may have taken the lock before failing; the release checks the owner.
            rescue($lock->release(...), report: false);

            throw $exception;
        }

        if ($taken) {
            return true;
        }

        if ($lock instanceof MemcachedLock) {
            $this->ensureMemcachedAnswered($lock, [Memcached::RES_NOTSTORED, Memcached::RES_DATA_EXISTS], 'Memcached refused a slot');
        }

        return false;
    }

    private function free(Lock $lock): void
    {
        if ($lock->release()) {
            return;
        }

        if ($lock instanceof MemcachedLock) {
            $this->ensureMemcachedAnswered($lock, [Memcached::RES_SUCCESS, Memcached::RES_NOTFOUND], 'Memcached could not release a slot');
        }
    }

    /**
     * Memcached answers false both when the slot is taken, or no longer owned,
     * and when a server is unreachable; only the result code tells them apart.
     * The lock is checked rather than the store, so that a Memcached store
     * behind a failover store is checked too.
     *
     * @param  list<int>  $expected  the result codes of a legitimate false
     */
    private function ensureMemcachedAnswered(MemcachedLock $lock, array $expected, string $failure): void
    {
        $memcached = (fn (): Memcached => $this->memcached)->call($lock);
        $code = $memcached->getResultCode();

        if (in_array($code, $expected, true)) {
            return;
        }

        throw new RuntimeException("{$failure}, result code {$code}: {$memcached->getResultMessage()}");
    }

    /**
     * @param  list<Lease>  $leases
     *
     * @throws Throwable
     */
    private function giveBackOrFail(array $leases): void
    {
        $failure = $this->giveBack($leases);

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    /**
     * Releases every lock, even after a failure, and returns the first failure.
     *
     * @param  list<Lease>  $leases
     */
    private function giveBack(array $leases): ?Throwable
    {
        $failure = null;

        foreach ($leases as $lease) {
            try {
                $this->free($lease->lock);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        return $failure;
    }

    /** @param  list<Lease>  $leases */
    private function reportFailureToGiveBack(array $leases): void
    {
        $failure = $this->giveBack($leases);

        if ($failure instanceof Throwable) {
            $this->exceptions->report(new StoreUnavailableException($failure));
        }
    }

    /** @param  list<Lease>  $leases */
    private function announceReleased(array $leases): void
    {
        foreach ($leases as $lease) {
            $this->events->dispatch(new SlotReleased($lease->limiter, $lease->key, $lease->max, microtime(true) - $lease->acquiredAt));
        }
    }

    private function store(): LockProvider
    {
        $name = $this->config->get('concurrency-limit.store');
        $store = $this->cache->store(is_string($name) ? $name : null)->getStore();

        if (! $store instanceof LockProvider) {
            $class = $store::class;
            $contract = LockProvider::class;

            throw new LogicException("Concurrency slots need a cache store that supports locks, but {$class} does not implement {$contract}.");
        }

        return $store;
    }

    /** @param  non-empty-list<Slots>  $limits */
    private function ensureEachLimitHasItsOwnKey(string $limiter, array $limits): void
    {
        $keys = [];

        foreach ($limits as $slots) {
            $key = $this->keyOf($limiter, $slots);

            if (in_array($key, $keys, true)) {
                throw new LogicException("The [{$limiter}] limiter has two limits with the key [{$key}].");
            }

            $keys[] = $key;
        }
    }

    /**
     * The default key (user, else IP) depends on the request, so the HTTP
     * middleware resolves it; an unresolved key here is a bug, not a pool
     * shared by everyone.
     */
    private function keyOf(string $limiter, Slots $slots): string
    {
        return $slots->key ?? throw new LogicException("Every limit of the [{$limiter}] limiter needs a key; resolve the default key before acquiring.");
    }

    /**
     * A limit of zero never grants a slot, so waiting for it is pointless.
     *
     * @param  non-empty-list<Slots>  $limits
     */
    private function waitBudget(array $limits): int
    {
        if (array_any($limits, fn (Slots $slots): bool => $slots->max === 0)) {
            return 0;
        }

        $seconds = min(array_map(fn (Slots $slots): float => $slots->block, $limits));

        return (int) round($seconds * 1_000_000);
    }

    /**
     * One limit failing closed is enough: running without its slot would
     * break its guarantee, while rejecting only costs the others availability.
     *
     * @param  non-empty-list<Slots>  $limits
     */
    private function failsOpen(array $limits): bool
    {
        $default = (bool) $this->config->get('concurrency-limit.fail_open', true);

        return array_all($limits, fn ($slots) => $slots->failOpen ?? $default);
    }
}
