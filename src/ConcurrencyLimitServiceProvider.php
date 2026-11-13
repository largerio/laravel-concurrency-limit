<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit;

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Largerio\ConcurrencyLimit\Http\Middleware\LimitConcurrentRequests;

final class ConcurrencyLimitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/concurrency-limit.php', 'concurrency-limit');

        $this->app->singleton(ConcurrencyLimitManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/concurrency-limit.php' => config_path('concurrency-limit.php'),
        ], 'concurrency-limit-config');

        $this->app->make(Router::class)->aliasMiddleware('concurrency', LimitConcurrentRequests::class);

        /*
         * After authentication, so the default key sees the user; after rate
         * limiting, so a throttled request takes no slot; before route model
         * binding, so a rejection costs no query.
         */
        $this->callAfterResolving(HttpKernelContract::class, function (HttpKernel $kernel): void {
            $kernel->addToMiddlewarePriorityAfter([
                AuthenticatesRequests::class,
                ThrottleRequests::class,
                ThrottleRequestsWithRedis::class,
            ], LimitConcurrentRequests::class);
        });
    }
}
