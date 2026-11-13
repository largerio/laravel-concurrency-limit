<?php

declare(strict_types=1);

use Largerio\ConcurrencyLimit\Exceptions\ConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\Slots;
use Largerio\ConcurrencyLimit\Tests\Integration\Support\StoreUnderTest;
use Largerio\ConcurrencyLimit\Tests\Integration\Support\Workers;

/** Points this test at the store and returns a limiter name no other run uses. */
function onStore(StoreUnderTest $store): string
{
    $store->prepare();

    config($store->config);

    return 'integration-'.bin2hex(random_bytes(6));
}

/** Every slot can be taken at once again, and not one more. */
function expectNoLeak(string $limiter, string $key, int $max): void
{
    $pool = slotPool();
    $leases = [];

    foreach (range(1, $max) as $slot) {
        array_push($leases, ...$pool->acquire($limiter, [Slots::max($max)->by($key)->failClosed()]));
    }

    expect($leases)->toHaveCount($max)
        ->and(fn (): array => $pool->acquire($limiter, [Slots::max($max)->by($key)->failClosed()]))
        ->toThrow(ConcurrencyLimitExceededException::class);

    $pool->release($leases);
}

it('never lets more than max requests in at once', function (StoreUnderTest $store): void {
    $limiter = onStore($store);

    $results = Workers::run(40, ['config' => $store->config, 'limiter' => $limiter, 'key' => 'k', 'max' => 3, 'work_ms' => 200]);
    $statuses = array_count_values(array_column($results, 'status'));
    $admitted = array_filter($results, fn (array $result): bool => $result['status'] === 200);

    expect(Workers::maxOverlap($results))->toBeLessThanOrEqual(3)
        ->and($statuses[200] ?? 0)->toBeGreaterThanOrEqual(3)
        ->and(($statuses[200] ?? 0) + ($statuses[429] ?? 0))->toBe(40)
        ->and(array_column($admitted, 'leased'))->each->toBeTrue()
        ->and(array_merge(...array_column($results, 'reports')))->toBe([]);

    expectNoLeak($limiter, 'k', 3);
})->with('stores');

it('lets every waiting request in, a few at a time', function (StoreUnderTest $store): void {
    $limiter = onStore($store);

    $workers = new Workers(12, ['config' => $store->config, 'limiter' => $limiter, 'key' => 'k', 'max' => 2, 'block' => 5.0, 'work_ms' => 100]);
    $results = $workers->go()->results();
    $starts = array_column($results, 'start');

    expect(array_column($results, 'status'))->each->toBe(200)
        ->and(array_column($results, 'leased'))->each->toBeTrue()
        ->and(Workers::maxOverlap($results))->toBeLessThanOrEqual(2)
        ->and(min($starts) - $workers->startAt)->toBeLessThan(1.0)
        ->and(max(array_column($results, 'done')) - $workers->startAt)->toBeLessThan(4.0);

    expectNoLeak($limiter, 'k', 2);
})->with('stores');

it('frees the slot of a killed worker when it expires', function (StoreUnderTest $store): void {
    $limiter = onStore($store);
    $slots = fn (): Slots => Slots::max(1)->by('k')->failClosed();

    $workers = new Workers(1, ['config' => $store->config, 'limiter' => $limiter, 'key' => 'k', 'max' => 1, 'expire_after' => 2, 'hold' => true]);
    $heldAt = $workers->go()->heldAt(0);
    $workers->kill();

    expect(fn (): array => slotPool()->acquire($limiter, [$slots()]))->toThrow(ConcurrencyLimitExceededException::class);

    $grantedAt = null;

    while ($grantedAt === null && microtime(true) < $heldAt + 6) {
        try {
            slotPool()->release(slotPool()->acquire($limiter, [$slots()]));
            $grantedAt = microtime(true);
        } catch (ConcurrencyLimitExceededException) {
            usleep(50_000);
        }
    }

    expect($grantedAt)->not->toBeNull()
        ->and($grantedAt - $heldAt)->toBeLessThan(3.5);
})->with('stores');

it('lets requests through and reports each failure once when the store is down and limits fail open', function (StoreUnderTest $store): void {
    $store->prepare();

    $results = Workers::run(3, ['config' => $store->broken, 'limiter' => 'integration-down', 'key' => 'k', 'max' => 2, 'fail_open' => true, 'work_ms' => 10]);

    foreach ($results as $result) {
        expect($result['status'])->toBe(200)
            ->and($result['start'])->not->toBeNull()
            ->and($result['leased'])->toBeFalse()
            ->and($result['reports'])->toHaveCount(1)
            ->and($result['reports'][0]['class'])->toBe(StoreUnavailableException::class)
            ->and($result['reports'][0]['previous'])->toBe($store->failure);
    }
})->with('stores');

it('rejects requests and reports each failure once when the store is down and limits fail closed', function (StoreUnderTest $store): void {
    $store->prepare();

    $results = Workers::run(3, ['config' => $store->broken, 'limiter' => 'integration-down', 'key' => 'k', 'max' => 2, 'fail_open' => false, 'work_ms' => 10]);

    foreach ($results as $result) {
        expect($result['status'])->toBe(503)
            ->and($result['start'])->toBeNull()
            ->and($result['reports'])->toHaveCount(1)
            ->and($result['reports'][0]['class'])->toBe(StoreUnavailableException::class)
            ->and($result['reports'][0]['previous'])->toBe($store->failure);
    }
})->with('stores');

it('tells a full Memcached pool from an unreachable server', function (bool $binaryProtocol): void {
    $limiter = onStore(StoreUnderTest::memcached($binaryProtocol));
    $pool = slotPool();

    $held = $pool->acquire($limiter, [Slots::max(2)->by('k')->failClosed()]);
    $held = [...$held, ...$pool->acquire($limiter, [Slots::max(2)->by('k')->failClosed()])];

    expect($held)->toHaveCount(2)
        ->and(fn (): array => $pool->acquire($limiter, [Slots::max(2)->by('k')->failClosed()]))
        ->toThrow(ConcurrencyLimitExceededException::class);

    $pool->release($held);
})->with(['text protocol' => false, 'binary protocol' => true]);
