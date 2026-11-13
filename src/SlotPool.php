<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit;

use Illuminate\Cache\MemcachedStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
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

        try {
            foreach ($result as $lease) {
                $this->events->dispatch(new SlotAcquired($limiter, $lease->key, $lease->max, $waited));
            }
        } catch (Throwable $exception) {
            $this->giveBack($result);

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
        $failure = $this->giveBack($leases);

        if ($failure instanceof Throwable) {
            $this->exceptions->report(new StoreUnavailableException($failure));
        }

        foreach ($leases as $lease) {
            $this->events->dispatch(new SlotReleased($lease->limiter, $lease->key, $lease->max, microtime(true) - $lease->acquiredAt));
        }
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
        $pool = hash('xxh128', json_encode([$limiter, $key], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $start = random_int(0, $slots->max - 1);

        for ($offset = 0; $offset < $slots->max; $offset++) {
            $slot = ($start + $offset) % $slots->max + 1;
            $lock = $store->lock("concurrency:{$pool}:{$slot}", $slots->expireAfter);

            if ($lock->get() === true) {
                return new Lease($lock, $limiter, $key, $slots->max, microtime(true));
            }
        }

        if ($store instanceof MemcachedStore) {
            $this->ensureMemcachedRefused($store->getMemcached());
        }

        return null;
    }

    /**
     * Memcached::add() returns false both when the key exists and when the
     * server is unreachable; only the result code tells them apart.
     */
    private function ensureMemcachedRefused(Memcached $memcached): void
    {
        $code = $memcached->getResultCode();

        if (! in_array($code, [Memcached::RES_NOTSTORED, Memcached::RES_DATA_EXISTS], true)) {
            throw new RuntimeException("Memcached refused every slot with result code {$code}: {$memcached->getResultMessage()}");
        }
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
                $lease->lock->release();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        return $failure;
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

    /** @param  non-empty-list<Slots>  $limits */
    private function waitBudget(array $limits): int
    {
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
