<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Largerio\ConcurrencyLimit\ConcurrencyLimitServiceProvider;

it('merges the package configuration', function (): void {
    expect(config('concurrency-limit'))->toBe([
        'store' => null,
        'fail_open' => true,
    ]);
});

it('publishes the configuration file', function (): void {
    $paths = ServiceProvider::pathsToPublish(ConcurrencyLimitServiceProvider::class, 'concurrency-limit-config');

    expect($paths)->toHaveCount(1)
        ->and(realpath((string) array_key_first($paths)))->toBe(realpath(__DIR__.'/../../config/concurrency-limit.php'))
        ->and(reset($paths))->toBe(config_path('concurrency-limit.php'));
});
