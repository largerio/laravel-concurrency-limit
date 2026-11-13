<?php

declare(strict_types=1);

use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\Tests\Integration\Support\StoreUnderTest;
use Largerio\ConcurrencyLimit\Tests\Integration\Support\Workers;

it('never lets more than max requests through the middleware at once', function (StoreUnderTest $store): void {
    $limiter = onStore($store);

    $results = Workers::run(40, ['config' => $store->config, 'limiter' => $limiter, 'key' => 'k', 'max' => 3, 'work_ms' => 200, 'via' => 'http']);
    $statuses = array_count_values(array_column($results, 'status'));
    $admitted = array_filter($results, fn (array $result): bool => $result['status'] === 200);
    $rejected = array_filter($results, fn (array $result): bool => $result['status'] === 429);

    expect(Workers::maxOverlap($results))->toBeLessThanOrEqual(3)
        ->and($statuses[200] ?? 0)->toBeGreaterThanOrEqual(3)
        ->and(($statuses[200] ?? 0) + ($statuses[429] ?? 0))->toBe(40)
        ->and(array_column($admitted, 'leased'))->each->toBeTrue()
        ->and(array_column($rejected, 'start'))->each->toBeNull()
        ->and(array_column($rejected, 'retry_after'))->each->toBe('1')
        ->and(array_merge(...array_column($results, 'reports')))->toBe([]);

    expectNoLeak($limiter, 'k', 3);
})->with('stores');

it('holds the slot of a streamed response until its body is sent', function (StoreUnderTest $store): void {
    $limiter = onStore($store);

    $results = Workers::run(20, ['config' => $store->config, 'limiter' => $limiter, 'key' => 'k', 'max' => 2, 'work_ms' => 200, 'via' => 'stream']);
    $statuses = array_count_values(array_column($results, 'status'));
    $admitted = array_filter($results, fn (array $result): bool => $result['status'] === 200);

    expect(Workers::maxOverlap($results))->toBeLessThanOrEqual(2)
        ->and($statuses[200] ?? 0)->toBeGreaterThanOrEqual(2)
        ->and(($statuses[200] ?? 0) + ($statuses[429] ?? 0))->toBe(20)
        ->and(array_column($admitted, 'start'))->each->not->toBeNull()
        ->and(array_column($admitted, 'leased'))->each->toBeTrue()
        ->and(array_merge(...array_column($results, 'reports')))->toBe([]);

    expectNoLeak($limiter, 'k', 2);
})->with('stores');

it('lets requests through the middleware and reports each failure once when the store is down and limits fail open', function (StoreUnderTest $store): void {
    $store->prepare();

    $results = Workers::run(3, ['config' => $store->broken, 'limiter' => 'integration-down', 'key' => 'k', 'max' => 2, 'fail_open' => true, 'work_ms' => 10, 'via' => 'http']);

    foreach ($results as $result) {
        expect($result['status'])->toBe(200)
            ->and($result['start'])->not->toBeNull()
            ->and($result['leased'])->toBeFalse()
            ->and($result['reports'])->toHaveCount(1)
            ->and($result['reports'][0]['class'])->toBe(StoreUnavailableException::class)
            ->and($result['reports'][0]['previous'])->toBe($store->failure);
    }
})->with('stores');

it('answers 503 and reports each failure once when the store is down and limits fail closed', function (StoreUnderTest $store): void {
    $store->prepare();

    $results = Workers::run(3, ['config' => $store->broken, 'limiter' => 'integration-down', 'key' => 'k', 'max' => 2, 'fail_open' => false, 'work_ms' => 10, 'via' => 'http']);

    foreach ($results as $result) {
        expect($result['status'])->toBe(503)
            ->and($result['retry_after'])->toBe('1')
            ->and($result['start'])->toBeNull()
            ->and($result['reports'])->toHaveCount(1)
            ->and($result['reports'][0]['class'])->toBe(StoreUnavailableException::class)
            ->and($result['reports'][0]['previous'])->toBe($store->failure);
    }
})->with('stores');
