<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Largerio\ConcurrencyLimit\SlotPool;
use Largerio\ConcurrencyLimit\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

pest()->extend(TestCase::class)->group('integration')->in('Integration');

/**
 * Resolved after any Event::fake() or Exceptions::fake() of the test, like
 * the middlewares resolve it for each request.
 */
function slotPool(): SlotPool
{
    return app(SlotPool::class);
}

function useStore(Store $store): void
{
    Cache::extend('fixture', fn (): Repository => Cache::repository($store));

    config([
        'cache.stores.fixture' => ['driver' => 'fixture'],
        'concurrency-limit.store' => 'fixture',
    ]);
}

function slotName(string $limiter, string $key, int $slot): string
{
    $pool = hash('xxh128', json_encode([$limiter, $key], JSON_THROW_ON_ERROR));

    return "concurrency:{$pool}:{$slot}";
}
