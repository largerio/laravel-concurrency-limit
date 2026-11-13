<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\ServiceProvider;
use Largerio\ConcurrencyLimit\ConcurrencyLimitManager;
use Largerio\ConcurrencyLimit\ConcurrencyLimitServiceProvider;
use Largerio\ConcurrencyLimit\Facades\ConcurrencyLimit;
use Largerio\ConcurrencyLimit\Http\Middleware\LimitConcurrentRequests;
use Largerio\ConcurrencyLimit\Slots;

/**
 * Builds an application the way bootstrap/app.php does, with its own
 * middleware priority list, boots the package in it, and returns the
 * priority list its HTTP kernel ends up with.
 *
 * @param  list<string>  $priority
 * @return list<string>
 */
function priorityOfAnApplicationWith(array $priority, bool $kernelResolvedFirst): array
{
    $testApplication = Container::getInstance();
    $app = new Application(dirname(__DIR__, 2));
    $app->instance('config', new Repository);

    new ApplicationBuilder($app)
        ->withKernels()
        ->withMiddleware(fn (Middleware $middleware): Middleware => $middleware->priority($priority));

    try {
        // Application::handleRequest() resolves the kernel before the providers boot;
        // a test or a console command boots them before anything resolves it.
        $kernel = $kernelResolvedFirst ? $app->make(HttpKernel::class) : null;

        $app->register(ConcurrencyLimitServiceProvider::class);
        $app->boot();

        return ($kernel ?? $app->make(HttpKernel::class))->getMiddlewarePriority();
    } finally {
        Container::setInstance($testApplication);
    }
}

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

it('shares one manager, behind the facade', function (): void {
    ConcurrencyLimit::for('api', fn (): Slots => Slots::max(3));

    [, [$slots]] = app(ConcurrencyLimitManager::class)->resolve(Request::create('/'), 'api');

    expect(app(ConcurrencyLimitManager::class))->toBe(ConcurrencyLimit::getFacadeRoot())
        ->and($slots->max)->toBe(3);
});

it('declares the facade alias for package discovery', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['laravel']['aliases'])->toBe(['ConcurrencyLimit' => ConcurrencyLimit::class]);
});

it('registers the concurrency middleware alias', function (): void {
    expect(app('router')->getMiddleware())->toHaveKey('concurrency', LimitConcurrentRequests::class);
});

it('places the middleware after authentication and rate limiting, before route model binding', function (): void {
    $priority = app(HttpKernel::class)->getMiddlewarePriority();
    $position = array_search(LimitConcurrentRequests::class, $priority, true);

    expect(array_slice($priority, (int) $position - 3, 6))->toBe([
        AuthenticatesRequests::class,
        ThrottleRequests::class,
        ThrottleRequestsWithRedis::class,
        LimitConcurrentRequests::class,
        AuthenticatesSessions::class,
        SubstituteBindings::class,
    ]);
});

describe('an application that sets its own middleware priority', function (): void {
    it('still places the middleware right after the authentication and rate limiting middleware of the list', function (array $priority, array $expected, bool $kernelResolvedFirst): void {
        expect(priorityOfAnApplicationWith($priority, $kernelResolvedFirst))->toBe($expected);
    })->with([
        'authentication, then rate limiting' => [
            [StartSession::class, AuthenticatesRequests::class, ThrottleRequests::class, SubstituteBindings::class],
            [StartSession::class, AuthenticatesRequests::class, ThrottleRequests::class, LimitConcurrentRequests::class, SubstituteBindings::class],
        ],
        'route model binding before authentication' => [
            [StartSession::class, ThrottleRequests::class, SubstituteBindings::class, AuthenticatesRequests::class],
            [StartSession::class, ThrottleRequests::class, SubstituteBindings::class, AuthenticatesRequests::class, LimitConcurrentRequests::class],
        ],
    ])->with([
        'kernel resolved before the providers boot' => true,
        'kernel resolved after the providers boot' => false,
    ]);

    it('appends the middleware to a list without authentication or rate limiting', function (bool $kernelResolvedFirst): void {
        expect(priorityOfAnApplicationWith([StartSession::class, SubstituteBindings::class], $kernelResolvedFirst))
            ->toBe([StartSession::class, SubstituteBindings::class, LimitConcurrentRequests::class]);
    })->with([
        'kernel resolved before the providers boot' => true,
        'kernel resolved after the providers boot' => false,
    ]);

    it('keeps the place the list gives the middleware', function (bool $kernelResolvedFirst): void {
        $priority = [LimitConcurrentRequests::class, AuthenticatesRequests::class, ThrottleRequests::class];

        expect(priorityOfAnApplicationWith($priority, $kernelResolvedFirst))->toBe($priority);
    })->with([
        'kernel resolved before the providers boot' => true,
        'kernel resolved after the providers boot' => false,
    ]);
});
