<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\LazyCollection;
use Largerio\ConcurrencyLimit\Events\ConcurrencyLimitExceeded;
use Largerio\ConcurrencyLimit\Events\SlotAcquired;
use Largerio\ConcurrencyLimit\Events\SlotReleased;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\Facades\ConcurrencyLimit;
use Largerio\ConcurrencyLimit\Http\Middleware\LimitConcurrentRequests;
use Largerio\ConcurrencyLimit\Slots;
use Largerio\ConcurrencyLimit\Tests\Fixtures\Admin;
use Largerio\ConcurrencyLimit\Tests\Fixtures\ThrowingOnReleaseStore;
use Largerio\ConcurrencyLimit\Tests\Fixtures\ThrowingStore;
use Largerio\ConcurrencyLimit\Tests\Fixtures\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** The number of slots held right now, on the array store. */
function slotsHeld(): int
{
    return count(Cache::store()->getStore()->locks);
}

/** Takes every slot of a pool, as other requests in flight would. */
function fillPool(string $limiter, string $key, int $max = 1): void
{
    foreach (range(1, $max) as $slot) {
        slotPool()->acquire($limiter, [Slots::max($max)->by($key)]);
    }
}

/**
 * Sends the body of a response, as Response::send() does between the
 * kernel's handle() and terminate(), and discards it.
 */
function sendBody(Response $response): void
{
    ob_start(fn (): string => '');
    $response->sendContent();
    ob_end_clean();
}

describe('admitting', function (): void {
    it('holds a slot while the route runs and releases it before returning', function (): void {
        Event::fake([SlotAcquired::class, SlotReleased::class]);
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(2));
        Route::get('/reports', fn (): string => 'held: '.slotsHeld())->middleware('concurrency:reports');

        $this->get('/reports')->assertOk()->assertSee('held: 1');

        expect(slotsHeld())->toBe(0);
        Event::assertDispatchedTimes(SlotAcquired::class, 1);
        Event::assertDispatchedTimes(SlotReleased::class, 1);
        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->limiter === 'reports'
            && $event->key === 'ip:127.0.0.1'
            && $event->max === 2);
    });

    it('frees the slot before the client can send its next request', function (): void {
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        Route::get('/reports', fn (): string => 'ok')->middleware('concurrency:reports');

        $this->get('/reports')->assertOk();
        $this->get('/reports')->assertOk();
    });

    it('applies no limit when the limiter returns null', function (): void {
        Event::fake();
        ConcurrencyLimit::for('ai', fn (): ?Slots => null);
        Route::get('/ai', fn (): string => 'held: '.slotsHeld())->middleware('concurrency:ai');

        $this->get('/ai')->assertOk()->assertSee('held: 0');

        Event::assertNotDispatched(SlotAcquired::class);
    });

    it('takes a slot from every limit', function (): void {
        Event::fake([SlotAcquired::class]);
        ConcurrencyLimit::for('exports', fn (): array => [
            Slots::max(2)->by('user:1'),
            Slots::max(10)->by('team:1'),
        ]);
        Route::get('/exports', fn (): string => 'held: '.slotsHeld())->middleware('concurrency:exports');

        $this->get('/exports')->assertOk()->assertSee('held: 2');

        expect(slotsHeld())->toBe(0);
        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === 'user:1' && $event->max === 2);
        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === 'team:1' && $event->max === 10);
    });

    it('is placed on a route without its alias with using()', function (): void {
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(0));
        Route::get('/reports', fn (): string => 'ok')->middleware(LimitConcurrentRequests::using('reports'));

        expect(LimitConcurrentRequests::using('reports'))->toBe(LimitConcurrentRequests::class.':reports');

        $this->get('/reports')->assertTooManyRequests();
    });
});

describe('rejecting', function (): void {
    it('answers 429 Too Many Concurrent Requests with Retry-After, without running the route', function (): void {
        Exceptions::fake();
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        $ran = false;
        Route::get('/reports', function () use (&$ran): string {
            $ran = true;

            return 'ok';
        })->middleware('concurrency:reports');
        fillPool('reports', 'ip:127.0.0.1');

        $this->get('/reports')->assertTooManyRequests()->assertHeader('Retry-After', '1');

        expect($ran)->toBeFalse();
        Exceptions::assertNothingReported();
    });

    it('renders the rejection as JSON for JSON clients', function (): void {
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(0));
        Route::get('/reports', fn (): string => 'ok')->middleware('concurrency:reports');

        $this->getJson('/reports')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('message', 'Too Many Concurrent Requests.');
    });

    it('sends no Retry-After when the limit retries after 0 seconds', function (): void {
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(0)->retryAfter(0));
        Route::get('/reports', fn (): string => 'ok')->middleware('concurrency:reports');

        $this->get('/reports')->assertTooManyRequests()->assertHeaderMissing('Retry-After');
    });

    it('gives back the slots already taken when a later limit refuses', function (): void {
        Event::fake([ConcurrencyLimitExceeded::class]);
        ConcurrencyLimit::for('exports', fn (): array => [
            Slots::max(2)->by('user:1'),
            Slots::max(1)->by('team:1')->retryAfter(30),
        ]);
        Route::get('/exports', fn (): string => 'ok')->middleware('concurrency:exports');
        fillPool('exports', 'team:1');

        $this->get('/exports')->assertTooManyRequests()->assertHeader('Retry-After', '30');

        expect(slotsHeld())->toBe(1);
        Event::assertDispatched(ConcurrencyLimitExceeded::class, fn (ConcurrencyLimitExceeded $event): bool => $event->key === 'team:1');
    });

    it('answers with the response() callback of the limit that refused, given the Retry-After header', function (): void {
        $given = null;
        ConcurrencyLimit::for('exports', function () use (&$given): array {
            return [
                Slots::max(2)->by('user:1')->response(fn (): Response => response('not this one', 500)),
                Slots::max(1)->by('team:1')->retryAfter(5)->response(function (Request $request, array $headers) use (&$given): Response {
                    $given = [$request->path(), $headers];

                    return response()->json(['message' => 'An export is already running.'], 429, $headers);
                }),
            ];
        });
        Route::get('/exports', fn (): string => 'ok')->middleware('concurrency:exports');
        fillPool('exports', 'team:1');

        $this->getJson('/exports')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '5')
            ->assertExactJson(['message' => 'An export is already running.']);

        expect($given)->toBe(['exports', ['Retry-After' => 5]]);
    });

    it('throws the default rejection when the limit that refused has no response() callback', function (): void {
        ConcurrencyLimit::for('exports', fn (): array => [
            Slots::max(1)->by('user:1'),
            Slots::max(1)->by('team:1')->response(fn (): Response => response('not this one', 503)),
        ]);
        Route::get('/exports', fn (): string => 'ok')->middleware('concurrency:exports');
        fillPool('exports', 'user:1');

        $this->getJson('/exports')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too Many Concurrent Requests.');
    });
});

describe('configuration errors', function (): void {
    it('throws on a limiter that was never defined', function (): void {
        $this->withoutExceptionHandling();
        Route::get('/reports', fn (): string => 'ok')->middleware('concurrency:reports');

        expect(fn () => $this->get('/reports'))
            ->toThrow(InvalidArgumentException::class, 'Concurrency limiter [reports] is not defined.');
    });

    it('throws on two limits with the same key, without taking a slot', function (): void {
        $this->withoutExceptionHandling();
        ConcurrencyLimit::for('exports', fn (): array => [Slots::max(1)->by('user:1'), Slots::max(2)->by('user:1')]);
        Route::get('/exports', fn (): string => 'ok')->middleware('concurrency:exports');

        expect(fn () => $this->get('/exports'))
            ->toThrow(LogicException::class, 'The [exports] limiter has two limits with the key [user:1].');

        expect(slotsHeld())->toBe(0);
    });
});

describe('inline form', function (): void {
    it('limits a route to a number of slots in the default pool', function (): void {
        Event::fake([SlotAcquired::class]);
        Route::get('/export', fn (): string => 'ok')->middleware('concurrency:2');

        $this->get('/export')->assertOk();
        fillPool('default', 'ip:127.0.0.1', 2);
        $this->get('/export')->assertTooManyRequests();

        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->limiter === 'default'
            && $event->key === 'ip:127.0.0.1'
            && $event->max === 2);
    });

    it('counts a route in the pool it names', function (): void {
        Route::get('/import', fn (): string => 'ok')->middleware('concurrency:1,imports');
        fillPool('default', 'ip:127.0.0.1');

        $this->get('/import')->assertOk();

        fillPool('imports', 'ip:127.0.0.1');

        $this->get('/import')->assertTooManyRequests();
    });
});

describe('default key', function (): void {
    beforeEach(function (): void {
        Auth::viaRequest('admin-header', fn (Request $request): ?Admin => $request->hasHeader('X-Admin') ? new Admin(['id' => (int) $request->header('X-Admin')]) : null);
        Auth::viaRequest('user-header', fn (Request $request): ?User => $request->hasHeader('X-User') ? new User(['id' => (int) $request->header('X-User')]) : null);
        Auth::viaRequest('anonymous-user', fn (): User => new User(['id' => null]));

        config([
            'auth.guards.admins' => ['driver' => 'admin-header'],
            'auth.guards.users' => ['driver' => 'user-header'],
            'auth.guards.api' => ['driver' => 'user-header'],
            'auth.guards.anonymous' => ['driver' => 'anonymous-user'],
        ]);

        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));

        foreach (['admins', 'users', 'api', 'anonymous'] as $guard) {
            Route::get("/{$guard}/reports", fn (): string => 'ok')->middleware("auth:{$guard}", 'concurrency:reports');
        }
    });

    it('is the class and identifier of the authenticated user', function (): void {
        Event::fake([SlotAcquired::class]);

        $this->get('/admins/reports', ['X-Admin' => '1'])->assertOk();
        $this->get('/users/reports', ['X-User' => '1'])->assertOk();

        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === Admin::class.':1');
        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === User::class.':1');
    });

    it('keeps two models with the same identifier in two pools', function (): void {
        fillPool('reports', User::class.':1');

        $this->get('/admins/reports', ['X-Admin' => '1'])->assertOk();
        $this->get('/users/reports', ['X-User' => '1'])->assertTooManyRequests();
        Auth::forgetGuards();
        $this->get('/users/reports', ['X-User' => '2'])->assertOk();
    });

    it('keeps one user reaching the application through two guards in one pool', function (): void {
        fillPool('reports', User::class.':1');

        $this->get('/api/reports', ['X-User' => '1'])->assertTooManyRequests();
    });

    it('is the client IP for guests', function (): void {
        Event::fake([SlotAcquired::class]);
        Route::get('/reports', fn (): string => 'ok')->middleware('concurrency:reports');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/reports')->assertOk();

        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === 'ip:203.0.113.7');
    });

    it('is the client IP for a user without a usable identifier', function (): void {
        Event::fake([SlotAcquired::class]);

        $this->get('/anonymous/reports')->assertOk();

        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === 'ip:127.0.0.1');
    });

    it('leaves the key of a limit that has one', function (): void {
        Event::fake([SlotAcquired::class]);
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1)->by('team:7'));

        $this->get('/users/reports', ['X-User' => '1'])->assertOk();

        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === 'team:7');
    });

    it('never writes it into a Slots instance shared between requests', function (): void {
        Event::fake([SlotAcquired::class]);
        $shared = Slots::max(1);
        ConcurrencyLimit::for('reports', fn (): Slots => $shared);

        $this->get('/users/reports', ['X-User' => '1'])->assertOk();
        Auth::forgetGuards();
        $this->get('/users/reports', ['X-User' => '2'])->assertOk();

        expect($shared->key)->toBeNull();
        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === User::class.':2');
    });
});

describe('streamed and file responses', function (): void {
    it('keeps the slot after handle() and frees it in terminate()', function (Closure $respond): void {
        ConcurrencyLimit::for('downloads', fn (): Slots => Slots::max(1));
        Route::get('/download', $respond)->middleware('concurrency:downloads');
        $kernel = app(HttpKernel::class);

        $response = $kernel->handle($request = Request::create('/download'));

        expect($response->getStatusCode())->toBe(200)
            ->and(slotsHeld())->toBe(1);

        sendBody($response);
        $kernel->terminate($request, $response);

        expect(slotsHeld())->toBe(0);
    })->with([
        'a stream' => [fn (): Response => response()->stream(fn (): int => print 'row')],
        'a JSON stream' => [fn (): Response => response()->streamJson(['rows' => LazyCollection::make(fn () => yield 'row')])],
        'an event stream' => [fn (): Response => response()->eventStream(fn () => yield 'tick')],
        'a streamed download' => [fn (): Response => response()->streamDownload(fn (): int => print 'row', 'report.csv')],
        'a file download' => [fn (): Response => response()->download(__FILE__)],
        'a file' => [fn (): Response => response()->file(__FILE__)],
    ]);

    it('holds the slot while the body is sent', function (): void {
        ConcurrencyLimit::for('downloads', fn (): Slots => Slots::max(1));
        $heldWhileStreaming = null;
        Route::get('/download', function () use (&$heldWhileStreaming): Response {
            return response()->stream(function () use (&$heldWhileStreaming): void {
                $heldWhileStreaming = slotsHeld();
            });
        })->middleware('concurrency:downloads');
        $kernel = app(HttpKernel::class);

        $response = $kernel->handle($request = Request::create('/download'));
        sendBody($response);
        $kernel->terminate($request, $response);

        expect($heldWhileStreaming)->toBe(1)
            ->and(slotsHeld())->toBe(0);
    });

    it('frees the slots of every limiter on the route once', function (): void {
        Event::fake([SlotAcquired::class, SlotReleased::class]);
        ConcurrencyLimit::for('downloads', fn (): Slots => Slots::max(1));
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        Route::get('/download', fn (): Response => response()->stream(fn (): int => print 'row'))
            ->middleware(['concurrency:downloads', 'concurrency:reports']);
        $kernel = app(HttpKernel::class);

        $response = $kernel->handle($request = Request::create('/download'));

        expect(slotsHeld())->toBe(2);

        $kernel->terminate($request, $response);

        expect(slotsHeld())->toBe(0);
        Event::assertDispatchedTimes(SlotAcquired::class, 2);
        Event::assertDispatchedTimes(SlotReleased::class, 2);
    });

    it('has nothing to free in terminate() for other responses', function (): void {
        Event::fake([SlotReleased::class]);
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        Route::get('/reports', fn (): string => 'ok')->middleware('concurrency:reports');
        $kernel = app(HttpKernel::class);

        $response = $kernel->handle($request = Request::create('/reports'));
        $kernel->terminate($request, $response);
        app(LimitConcurrentRequests::class)->terminate($request);

        Event::assertDispatchedTimes(SlotReleased::class, 1);
    });
});

describe('exceptions', function (): void {
    it('frees the slot and rethrows when the route throws', function (): void {
        $this->withoutExceptionHandling();
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        Route::get('/reports', fn (): never => throw new RuntimeException('Report failed'))->middleware('concurrency:reports');

        expect(fn () => $this->get('/reports'))->toThrow(RuntimeException::class, 'Report failed');

        expect(slotsHeld())->toBe(0);
    });

    it('frees the slot when the route throws and the exception is rendered', function (): void {
        Exceptions::fake();
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        Route::get('/reports', fn (): never => throw new RuntimeException('Report failed'))->middleware('concurrency:reports');

        $this->get('/reports')->assertServerError();

        expect(slotsHeld())->toBe(0);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(RuntimeException::class);
    });
});

describe('store failures', function (): void {
    it('lets the request through and reports once when failing open', function (): void {
        Exceptions::fake();
        Event::fake([SlotAcquired::class, SlotReleased::class]);
        useStore(new ThrowingStore);
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        Route::get('/reports', fn (): string => 'ran')->middleware('concurrency:reports');

        $this->get('/reports')->assertOk()->assertSee('ran');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(StoreUnavailableException::class);
        Event::assertNothingDispatched();
    });

    it('answers 503 with Retry-After and reports once when failing closed', function (): void {
        Exceptions::fake();
        useStore(new ThrowingStore);
        ConcurrencyLimit::for('reports', fn (): array => [
            Slots::max(1)->by('user:1')->retryAfter(5)->failClosed(),
            Slots::max(1)->by('team:1')->retryAfter(10),
        ]);
        $ran = false;
        Route::get('/reports', function () use (&$ran): string {
            $ran = true;

            return 'ran';
        })->middleware('concurrency:reports');

        $response = $this->getJson('/reports')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '10')
            ->assertJsonPath('message', 'Service Unavailable.');

        expect($ran)->toBeFalse()
            ->and($response->exception)->toBeInstanceOf(ServiceUnavailableHttpException::class)
            ->and($response->exception?->getPrevious())->toBeInstanceOf(StoreUnavailableException::class);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (StoreUnavailableException $exception): bool => $exception === $response->exception?->getPrevious());
    });

    it('leaves the response alone when releasing fails, and reports once', function (): void {
        Exceptions::fake();
        useStore(new ThrowingOnReleaseStore);
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        Route::get('/reports', fn (): string => 'ran')->middleware('concurrency:reports');

        $this->get('/reports')->assertOk()->assertSee('ran');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(StoreUnavailableException::class);
    });

    it('leaves a streamed response alone when releasing fails in terminate(), and reports once', function (): void {
        Exceptions::fake();
        useStore(new ThrowingOnReleaseStore);
        ConcurrencyLimit::for('downloads', fn (): Slots => Slots::max(1));
        Route::get('/download', fn (): Response => response()->stream(fn (): int => print 'row'))->middleware('concurrency:downloads');

        $this->get('/download')->assertOk();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(StoreUnavailableException::class);
    });
});

describe('middleware priority', function (): void {
    it('runs after authentication, whatever the order on the route', function (): void {
        Event::fake([SlotAcquired::class]);
        Auth::viaRequest('user-header', fn (Request $request): ?User => $request->hasHeader('X-User') ? new User(['id' => (int) $request->header('X-User')]) : null);
        config(['auth.guards.users' => ['driver' => 'user-header']]);
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        Route::get('/reports', fn (): string => 'ok')->middleware(['concurrency:reports', 'auth:users']);

        $this->get('/reports', ['X-User' => '1'])->assertOk();

        Event::assertDispatched(SlotAcquired::class, fn (SlotAcquired $event): bool => $event->key === User::class.':1');
    });

    it('runs after rate limiting, so a throttled request takes no slot', function (): void {
        Event::fake([SlotAcquired::class]);
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(1));
        Route::get('/reports', fn (): string => 'ok')->middleware(['concurrency:reports', 'throttle:1,1']);

        $this->getJson('/reports')->assertOk();
        $this->getJson('/reports')->assertTooManyRequests()->assertJsonPath('message', 'Too Many Attempts.');

        Event::assertDispatchedTimes(SlotAcquired::class, 1);
    });

    it('runs before route model binding, so a rejection costs no query', function (): void {
        $bound = false;
        Route::bind('report', function (string $value) use (&$bound): string {
            $bound = true;

            return $value;
        });
        ConcurrencyLimit::for('reports', fn (): Slots => Slots::max(0));
        Route::get('/reports/{report}', fn (string $report): string => $report)->middleware([SubstituteBindings::class, 'concurrency:reports']);

        $this->get('/reports/1')->assertTooManyRequests();

        expect($bound)->toBeFalse();
    });
});
