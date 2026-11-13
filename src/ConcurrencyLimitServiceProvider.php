<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit;

use Illuminate\Support\ServiceProvider;

final class ConcurrencyLimitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/concurrency-limit.php', 'concurrency-limit');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/concurrency-limit.php' => config_path('concurrency-limit.php'),
        ], 'concurrency-limit-config');
    }
}
