# Laravel Concurrency Limit

Cap the HTTP requests in flight and the queued jobs running at once, per user,
tenant or key.

Laravel can limit a *rate* (`throttle`, `RateLimited`) and can run a closure
under a concurrency cap (`Cache::funnel()`). It has no HTTP middleware that
caps the requests in flight, and no job middleware that lets N jobs run at
once (`WithoutOverlapping` lets one). This package adds both.

It does one thing: **application-level fairness**. One user, tenant or
resource cannot hold more than N executions at once, so it cannot monopolise
the workers everyone else needs.

```php
ConcurrencyLimit::for('exports', fn (Request $request) => Slots::max(2));

Route::post('/exports', ExportController::class)->middleware(['auth', 'concurrency:exports']);
```

- [When to use it](#when-to-use-it)
- [Installation](#installation)
- [Quick start](#quick-start)
- [HTTP middleware](#http-middleware)
- [Job middleware](#job-middleware)
- [Events](#events)
- [Testing your application](#testing-your-application)
- [Configuration](#configuration)
- [How slots work](#how-slots-work)
- [Cache stores](#cache-stores)
- [Things to know](#things-to-know)
- [Coming from laravel-concurrent-limiter](#coming-from-laravel-concurrent-limiter)
- [Credits](#credits)
- [Contributing](#contributing)
- [License](#license)

## When to use it

| You want to | Use |
|---|---|
| Protect the server as a whole from overload | Infrastructure: nginx `limit_conn`, Traefik `InFlightReq`, Envoy adaptive concurrency, PHP-FPM `pm.max_children` |
| Cap requests per minute | `throttle:` / `RateLimiter` |
| Cap one block of code (an API call inside a service) | `Cache::funnel()` directly |
| Cap the HTTP requests in flight per user, tenant or key | `concurrency:{name}` (this package) |
| Let at most N jobs run at once per key | `ConcurrencyLimited` (this package) |
| Hold a slot while a response streams | This package (`Cache::funnel()` releases its slot when its callback returns) |

Rate and concurrency limits complement each other: `throttle` stops many
cheap requests, `concurrency` stops a few expensive ones (exports, reports,
AI calls). Limits are meant to be small, from 1 to about 20 per key.

Out of scope: adaptive limits, metrics storage or endpoints, Artisan
commands, server-wide limits, and serving waiting requests in arrival order.

## Installation

Requires PHP 8.4 or later, Laravel 13.32 or later (`laravel/framework`), and
a cache store with atomic locks, such as Redis, Memcached or the database
store (see [Cache stores](#cache-stores)).

```bash
composer require largerio/laravel-concurrency-limit
```

The service provider and the `ConcurrencyLimit` facade are discovered
automatically. Publishing the configuration file is optional:

```bash
php artisan vendor:publish --tag=concurrency-limit-config
```

## Quick start

Register a named limiter, then put it on routes:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Http\Request;
use Largerio\ConcurrencyLimit\Facades\ConcurrencyLimit;
use Largerio\ConcurrencyLimit\Slots;

public function boot(): void
{
    ConcurrencyLimit::for('api', fn (Request $request) => Slots::max(10));
}
```

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'throttle:api', 'concurrency:api'])->group(function () {
    // ...
});
```

Each user, or each IP address for guests, now has at most 10 requests in
flight on these routes. The 11th gets a `429 Too Many Requests` with
`Retry-After: 1` at once, without reaching the controller.

To cap a job instead, see [Job middleware](#job-middleware).

## HTTP middleware

### Named limiters

```php
ConcurrencyLimit::for('api', fn (Request $request) => Slots::max(10));
```

The closure receives the request and returns a `Slots`, an array of `Slots`
([multiple limits](#multiple-limits)), or `null` for no limit on this
request. It runs on every request, so build the `Slots` inside it. A route
naming a limiter that was never registered throws an
`InvalidArgumentException` on its first request, and so does a limiter that
returns anything else.

`concurrency:api` is the middleware alias. For route definitions that avoid
aliases, `LimitConcurrentRequests::using('api')` returns the same middleware
named by its class (`LimitConcurrentRequests::class.':api'`), as
`ThrottleRequests::using()` does:

```php
use Largerio\ConcurrencyLimit\Http\Middleware\LimitConcurrentRequests;

Route::get('/reports', ReportController::class)->middleware(LimitConcurrentRequests::using('api'));
```

Routes that use the same limiter share its pools: with the limiter above, a
user has at most 10 requests in flight across all of them.

The name `job`, and any name that starts with `job:`, are reserved for the
pools of the [job middleware](#job-middleware): `ConcurrencyLimit::for()`
throws an `InvalidArgumentException` for them.

### `Slots`

| Method | Default | Meaning |
|---|---|---|
| `Slots::max(int $max)` | required | Maximum simultaneous executions per key. At least `0`; `0` always rejects, at once, even with `block()`. |
| `->by(string\|int $key)` | user, else IP | Key the limit is counted against. |
| `->block(int\|float $seconds)` | `0` | Opt-in: wait up to this long for a slot before rejecting. At least `0`. |
| `->expireAfter(int $seconds)` | `60` | Slot TTL: when a slot frees itself if it is never released. At least `1`. |
| `->retryAfter(int $seconds)` | `1` | Value of the `Retry-After` header on rejection. At least `0`; `0` sends no header. |
| `->response(Closure $callback)` | 429 exception | Custom rejection response. |
| `->failOpen()` / `->failClosed()` | config `fail_open` | Behavior when the cache store fails. |

### Keys

```php
// Default key: the authenticated user, else the client IP.
ConcurrencyLimit::for('reports', fn () => Slots::max(2));

// Custom key.
ConcurrencyLimit::for('exports', fn (Request $request) => Slots::max(3)->by($request->user()->team_id));

// Plan-dependent limit, no limit for staff.
ConcurrencyLimit::for('ai', fn (Request $request) => $request->user()->isStaff()
    ? null
    : Slots::max($request->user()->plan->concurrency));
```

The default key is the class of the authenticated user and its identifier
(`App\Models\User:42`), else the client IP (`ip:203.0.113.7`). So
`App\Models\Admin` #1 and `App\Models\User` #1 never share a pool, while one
user reaching the application through two guards (`web` and `sanctum`, say)
keeps a single pool. Two guards that share one model class for different
populations need a custom `by()`. A user whose identifier is neither an
integer nor a string is counted by IP address, like a guest.

A pool belongs to its limiter: the key `user:1` in `exports` and the key
`user:1` in `reports` are two pools. Keys can be any string, binary data
included: they are hashed byte for byte before they reach the cache.

Guests are counted by IP address, which depends on
[trusted proxies](#trusted-proxies).

### Multiple limits

```php
ConcurrencyLimit::for('exports', fn (Request $request) => [
    Slots::max(2)->by("user:{$request->user()->id}"),
    Slots::max(10)->by("team:{$request->user()->team_id}"),
]);
```

Every limit must grant a slot. When one refuses, the slots already taken for
this request are released before rejecting: all or nothing, so a request
never holds one slot while it waits for another. Two limits with the same key
in one array throw a `LogicException`.

### Waiting for a slot

```php
ConcurrencyLimit::for('checkout', fn () => Slots::max(1)->block(2));
```

By default a request is rejected at once. With `block()`, it polls for a free
slot, every 100 ms give or take 50% (the randomness keeps waiting requests
from polling in step), until the time is up. A waiting request holds a PHP
worker, so keep this to a couple of seconds.

Waiting requests hold no slot and reserve nothing, and they are not served in
arrival order: the next free slot goes to whichever request asks first. With
several limits, the wait is the smallest `block()` among them, and there is
none when one of them is `Slots::max(0)`, which never grants a slot.

### Rejection response

By default a rejection throws `ConcurrencyLimitExceededException`, which
extends Symfony's `TooManyRequestsHttpException`. The application's exception
handler renders it as `429 Too Many Requests`, with `Retry-After: 1`. JSON
clients get JSON, as with `throttle`, with the message
`Too Many Concurrent Requests.`

To answer something else:

```php
Slots::max(2)->retryAfter(5)->response(
    fn (Request $request, array $headers) => response()->json(['message' => 'An export is already running.'], 429, $headers)
);
```

The callback has the signature of `Illuminate\Cache\RateLimiting\Limit::response()`:
`$headers` holds the `Retry-After` header, and the callback returns the
response. No `X-RateLimit-*` header is sent: those describe a rate window,
and would be wrong here.

With [multiple limits](#multiple-limits), the `Retry-After` header and the
callback are those of the limit that refused.

### When the cache store fails

```php
Slots::max(5)->failClosed();   // per limiter

// config/concurrency-limit.php: 'fail_open' => true   (the global default)
```

- **Fail-open** (the default): the request or job runs without a slot.
- **Fail-closed**: a request gets a `503 Service Unavailable` with
  `Retry-After` (the largest `retryAfter()` among the request's limits); a
  job is released back to the queue, as when no slot is free, and a job that
  cannot be released throws (see
  [Jobs that cannot be released](#jobs-that-cannot-be-released)).

With [multiple limits](#multiple-limits), one limit that fails closed is
enough: running without its slot would break its guarantee.

In both modes the failure is passed to `report()` once, as a
`StoreUnavailableException` wrapping the error the store raised, and the
`concurrency` middleware never turns it into a `500`. The `503` is a Symfony
`ServiceUnavailableHttpException` whose previous exception is that
`StoreUnavailableException`: your exception handler renders it (JSON clients
get JSON) and, as for any HTTP exception, does not report it again. To
throttle or silence the reports during an outage, use the exception
handler's own tools:

```php
// bootstrap/app.php
use Illuminate\Cache\RateLimiting\Limit;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;

->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->throttle(fn (Throwable $e) => $e instanceof StoreUnavailableException
        ? Limit::perMinute(1)
        : Limit::none());

    // Or not at all:
    // $exceptions->dontReport(StoreUnavailableException::class);
})
```

Fail-open is the default because this is a fairness tool, not a security
control: a cache blip should neither take an API down nor burn job attempts.
Choose fail-closed where the limit protects something that must not be
overrun, such as a third-party quota.

A store that does not support locks is a configuration error, not a failure:
it throws a `LogicException` in both modes (see [Cache stores](#cache-stores)).

### Inline form

```php
Route::get('/export', ExportController::class)->middleware('concurrency:2');          // max 2, default key
Route::get('/import', ImportController::class)->middleware('concurrency:2,imports');  // separate pool "imports"
```

When the first parameter is not a registered limiter but a whole number, the
route gets `Slots::max(...)` with the default key, in the pool named by the
second parameter, `default` if there is none. Routes that use the inline form
without a pool name therefore share the `default` pool: name a pool to count
a route on its own. A pool name counts like a limiter name: a named limiter
of the same name, with the same key, shares its slots. The pool names
reserved for the job middleware, `job` and any name that starts with `job:`,
throw an `InvalidArgumentException` on the route's first request.

### Middleware order

The package places `concurrency` after authentication, `auth.basic`
included, and rate limiting in Laravel's middleware priority, and before
route model binding, whatever order the route lists them in:

- the default key sees the authenticated user;
- requests that `throttle` rejects take no slot;
- a rejection costs no database query.

`auth.basic` (`AuthenticateWithBasicAuth`) does not implement
`AuthenticatesRequests`, so Laravel leaves it out of the priority list: the
package adds it right after `AuthenticatesRequests`.

If your application sets its own priority list
(`$middleware->priority([...])` in `bootstrap/app.php`), the package still
adds `AuthenticateWithBasicAuth` right after `AuthenticatesRequests` when
your list contains the latter and does not place the former itself. It then
inserts `LimitConcurrentRequests` right after the last of
`AuthenticatesRequests`, `AuthenticateWithBasicAuth`, `ThrottleRequests` and
`ThrottleRequestsWithRedis` that your list contains. If your list puts
`SubstituteBindings` before them, the middleware comes after it too:
authentication first matters more. If your list contains none of the four,
the middleware ends up last, with no order relative to authentication: add
`LimitConcurrentRequests::class` to your list yourself, after the
authentication and throttling middleware. A place your list gives it is
kept.

### When a slot is released

- **Normal response**: before the response is returned. A client that sends
  its next request as soon as it has the answer finds its slot free.
- **Streamed responses** (`response()->stream()`,
  `response()->streamJson()`, `response()->eventStream()`,
  `response()->streamDownload()`): as soon as the body ends, whether it
  completes or throws. The stream is the work, so it keeps the slot while it
  runs. A body written as a generator stays a generator, so Octane still
  streams it itself. A stream that is never sent is released in the
  middleware's `terminate()`. Either way, the slot is released once.
- **File responses** (`response()->download()`, `response()->file()`): once
  the file is sent, in the middleware's `terminate()`.
- **Exception**: when the route throws, the slot is released and the
  exception rethrown.

## Job middleware

`ConcurrencyLimited` lets at most N jobs run at once per key. A job that
finds no free slot is released back to the queue, to try again later.

```php
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Largerio\ConcurrencyLimit\Queue\Middleware\ConcurrencyLimited;

class SyncCalendar implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public Calendar $calendar) {}

    public function retryUntil(): DateTime
    {
        return now()->addHour();
    }

    public function middleware(): array
    {
        return [
            (new ConcurrencyLimited("outlook-{$this->calendar->provider_id}", 4))
                ->shared()
                ->releaseAfter(10),
        ];
    }

    public function handle(): void
    {
        // At most 4 of these run at once per provider.
    }
}
```

**Set `$tries`, `retryUntil()` and `$maxExceptions` as above.** Laravel
counts every release as an attempt, as it does with `RateLimited` and
`WithoutOverlapping`. With the default of one try, the first release would
fail the job. So:

- `$tries = 0` lets the job be released as often as it takes;
- `retryUntil()` bounds the time it may keep trying;
- `$maxExceptions` bounds real failures: a release is not an exception, so
  only exceptions thrown by the job count against it.

### Constructor and methods

```php
new ConcurrencyLimited(
    UnitEnum|string $key = '',
    int $limit = 1,
    DateTimeInterface|int|null $releaseAfter = 10,
    DateTimeInterface|int|null $expiresAfter = null,
);
```

| Method | Meaning |
|---|---|
| `releaseAfter($delay)` | Delay before the job is tried again when no slot is free, in seconds (at least 0) or as a date. Default 10 s. |
| `jitter(int $seconds)` | A random extra delay, from 0 to `$seconds`, so jobs rejected together do not come back together. Default: half of `releaseAfter`; none when `releaseAfter` is a date. |
| `dontRelease()` | Skip the job instead: it is deleted. Explicit opt-in only. A `null` `$releaseAfter` does the same. |
| `expireAfter($seconds)` | Slot TTL, in seconds or as a date, at least 1 second: less, or a date that has passed, throws an `InvalidArgumentException` as soon as it is given. Default: the job's timeout plus 10 s, see below. |
| `shared()` | Share the pool across job classes. |
| `withPrefix(string $prefix)` | Change the pool's namespace: the prefix goes before the key. None by default. |
| `failOpen()` / `failClosed()` | Behavior when the cache store fails, as for [HTTP](#when-the-cache-store-fails). Fail-closed releases the job. |

By default each job class has its own pools, one per key. A queued listener,
notification or mailable counts as the class it wraps (its `displayName()`).
With `shared()`, every job class that uses the same key, and the same prefix,
shares one pool.

When the cache store fails, a job that fails open runs without a slot, and a
job that fails closed is handled as when no slot is free: released with its
usual delay, or skipped with `dontRelease()`. The failure is reported once
and not rethrown, so it does not count against `$maxExceptions`. A job that
cannot be released throws instead: see
[Jobs that cannot be released](#jobs-that-cannot-be-released).

### Slot TTL

A job that times out is killed by the worker: nothing runs after it, and only
the slot's TTL frees the slot. So the TTL follows the job's timeout: the
job's `$timeout` plus 10 seconds, or 70 seconds without one (the worker's
default `--timeout` of 60 seconds, plus margin). A `$timeout` of 0, which
turns the timeout off, counts as none. **If your workers run with a longer
`--timeout` and the job sets no `$timeout`, set `$timeout` on the job or call
`expireAfter()`**, or a long job may lose its slot while it still runs.

### Jobs that cannot be released

Releasing a job takes a real queue to run it again, and `InteractsWithQueue`
to reach it (`Illuminate\Foundation\Queue\Queueable`, as in the example
above, includes it). When no slot is free and the job cannot be released,
the middleware throws a `JobConcurrencyLimitExceededException` rather than
lose the job, even with `dontRelease()`. That is the case of:

- any job the sync driver runs: `dispatchSync()`, the `sync`, `deferred` and
  `background` connections, and `dispatchAfterResponse()`;
- a job without `InteractsWithQueue`, on any connection.

The exception extends `RuntimeException`, not an HTTP exception, so your
exception handler reports it and the job never vanishes without a trace. Its
`$limiter` property is the pool (`job:{JobClass}`, or `job` with
`shared()`), its `$slots` property the limit that refused, and its previous
exception is the `ConcurrencyLimitExceededException` of the rejection, or the
`StoreUnavailableException` when the store failed and the job fails closed.
In that last case there are two reports: the store failure, then the lost
job.

Where the exception goes depends on how the job runs:

- **`dispatchSync()` and the `sync` connection**: to the code that dispatched
  the job, once the job's `failed()` method has run. Left uncaught during a
  request, it is reported and the client gets a `500`, as with any
  exception. To answer otherwise, catch it:

  ```php
  use Largerio\ConcurrencyLimit\Exceptions\JobConcurrencyLimitExceededException;

  try {
      GenerateReport::dispatchSync($report);
  } catch (JobConcurrencyLimitExceededException) {
      return response()->json(['message' => 'A report is already being generated.'], 429);
  }
  ```

- **`deferred`, `background` and `dispatchAfterResponse()`**: the job runs
  once the response is sent, or in another process, where no caller is left
  to catch the exception. The job fails there: its `failed()` method runs,
  `JobFailed` is dispatched and the exception is reported, but the job is not
  retried.
- **A job without `InteractsWithQueue` on a real queue**: the worker handles
  the exception as any other the job throws. It reports it, then retries or
  fails the job according to its `$tries`, `retryUntil()`, `$maxExceptions`
  and backoff.

Put limited jobs on a real queue, with `InteractsWithQueue`.

`block()` does not exist for jobs: a waiting job would hold a worker, while
releasing is the queue's own way to wait.

### Differences from laravel/framework#62148

The constructor's first two arguments and the methods `releaseAfter()`,
`dontRelease()`, `expireAfter()`, `shared()` and `withPrefix()` match the
proposal in [laravel/framework#62148](https://github.com/laravel/framework/pull/62148),
so moving to a core class, should one ship, would be a `use` change. The
differences are deliberate:

- A slot always expires. With no TTL, a worker killed mid-job would hold its
  slot forever.
- The release delay defaults to 10 seconds plus jitter, not 0: an immediate
  release spins the queue and burns attempts.
- A job that cannot be released throws a
  `JobConcurrencyLimitExceededException`, which the exception handler
  reports, rather than vanish.
- There is no prefix by default, rather than `laravel-queue-concurrency:`:
  the package names and hashes its own cache keys, so the prefix only tells
  pools apart.
- `jitter()`, `failOpen()` and `failClosed()` are new.

## Events

All in `Largerio\ConcurrencyLimit\Events`, with public readonly properties:

| Event | Dispatched | Properties |
|---|---|---|
| `SlotAcquired` | Once per slot granted | `string $limiter`, `string $key`, `int $max`, `float $waited` (seconds) |
| `ConcurrencyLimitExceeded` | Once per rejection | `string $limiter`, `string $key`, `int $max`, `float $waited` (seconds) |
| `SlotReleased` | Once per slot released | `string $limiter`, `string $key`, `int $max`, `float $held` (seconds) |

`$key` is the key as given, before hashing. For jobs, `$limiter` is
`job:{JobClass}`, or `job` with `shared()`, and `$key` starts with the
`withPrefix()` prefix, if any; a job skipped with `dontRelease()`, or that
cannot be released, is a rejection too. Nothing is dispatched on a store
failure, whether the limit fails open or closed: the report is the signal.

The counts of `SlotAcquired` and `SlotReleased` stay balanced. If a
`SlotAcquired` listener throws, every slot just taken is released,
`SlotReleased` is dispatched for each `SlotAcquired` already dispatched, and
the listener's exception is rethrown.

Metrics are a listener away:

```php
use Largerio\ConcurrencyLimit\Events\ConcurrencyLimitExceeded;

Event::listen(fn (ConcurrencyLimitExceeded $event) => Metrics::increment("concurrency.rejected.{$event->limiter}"));
```

## Testing your application

There is no `ConcurrencyLimit::fake()`: the real middleware runs in the
test process, on the `array` store. Three recipes cover what you need:

```php
// 1. Nothing to do: with CACHE_STORE=array (Laravel's default in phpunit.xml),
//    the middleware works in-process and never rejects a single request.

// 2. Force the rejection path to test your 429 handling.
ConcurrencyLimit::for('exports', fn () => Slots::max(0));
$this->postJson('/exports')->assertTooManyRequests()->assertHeader('Retry-After', 1);

// 3. Assert a route or job is limited.
Event::fake([SlotAcquired::class]);
$this->post('/exports');
Event::assertDispatched(SlotAcquired::class, fn ($event) => $event->limiter === 'exports');
```

`withoutMiddleware(LimitConcurrentRequests::class)` works as for any
middleware. A limiter that waits (`block()`) waits under `Sleep::fake()`
without slowing the test, and `Sleep::assertSlept()` sees its pauses.

## Configuration

`config/concurrency-limit.php` has two keys. Everything else is set per
limiter, in code, next to the limit it qualifies.

```php
return [
    'store' => env('CONCURRENCY_LIMIT_STORE'),
    'fail_open' => true,
];
```

| Key | Default | Meaning |
|---|---|---|
| `store` | `null`: the default cache store | The cache store that holds the slots. Point it at a dedicated Redis without touching code. |
| `fail_open` | `true` | What happens when the store fails, unless a limit says otherwise ([details](#when-the-cache-store-fails)). |

The defaults, and why:

| Setting | Default | Why |
|---|---|---|
| `Slots::block()` | `0` | Reject at once. Waiting holds workers and makes overload worse. |
| Rejection status | `429` | The client is over its own limit. `503` is kept for fail-closed. |
| `Slots::retryAfter()` | `1` s | Slots free up when a request ends, usually within a second. Raise it for long-running endpoints. |
| `Slots::expireAfter()` | `60` s | Must exceed the longest request: PHP's default `max_execution_time` is 30 s. Raise it for long streams. |
| Poll interval while waiting | 100 ms ± 50% | Reactive enough for a wait of a second or two. Not configurable. |
| Job `releaseAfter()` | `10` s | Long enough not to spin the queue, short enough to keep the throughput. |
| Job `jitter()` | Half of `releaseAfter`, none for a date | Spreads the retries of jobs rejected together. |
| Job `expireAfter()` | Job timeout + 10 s, else 70 s | Just above the point where the worker kills the job. |

A TTL that is too short over-admits silently: the slot expires while the
work goes on, and one more execution gets in. A TTL that is too long keeps
the slot of a crashed process unavailable for longer. The defaults lean
slightly long.

## How slots work

Each pool, a limiter and a key, has `max` slots, and each slot is one cache
lock, taken with the store's atomic lock operation:

```
concurrency:{xxh128 hash of the limiter and the key}:{slot number}
```

- A request tries the slots once each, starting from a random one, and takes
  the first free one. Each slot has its own TTL (`expireAfter()`), so a
  process that dies holding a slot frees it when the TTL runs out. No cleanup
  command is needed.
- A slot is released with the lock's owner token: a release that comes after
  the slot expired cannot free a slot someone else has taken since.
- There is no counter to drift. Raising or lowering `max` between deploys is
  safe: slots are numbered, and a lower limit stops handing out the high
  ones.
- Each operation touches a single key, so Redis Cluster works.
- Keys are hashed with xxh128 together with the limiter name, byte for byte,
  so any string works, binary data included: they have a fixed length, fit
  every store's key limits, and don't appear in clear text in the cache.
  xxh128 is not a cryptographic hash, though: anyone who can read the cache
  can still test guesses.

The cost is in round trips to the store: a rejection tries every slot, so it
costs `max` round trips, and so does every poll of a waiting request. That
is why limits are meant to be small.

## Cache stores

Any store that implements `Illuminate\Contracts\Cache\LockProvider` works.

| Store | Notes |
|---|---|
| `redis` | Covered by the integration tests, with phpredis and with predis. |
| `memcached` | Covered by the integration tests. See [Memcached](#memcached). |
| `database` | Covered by the integration tests. See [Database store and transactions](#database-store-and-transactions). |
| `file` | Covered by the integration tests. Locks live on the server's disk: one server only. |
| `dynamodb` | Implements locks, but the integration tests don't cover it. |
| `array` | One process only: fine for tests, meaningless under PHP-FPM, where each worker has its own. |
| `null` | Grants every slot, so nothing is limited: a way to turn the package off in one environment. |
| `failover` | Supported, with a limit: it moves on to the next store only when a store fails to create the lock, which in practice means a phpredis connection that cannot be opened. When the error only comes as the lock is taken (predis, the database store, a connection lost while the process runs), the limit fails open or closed as with any store. That is also the case of Memcached, whose failures the package detects behind `failover` as without it (see [Memcached](#memcached)). Slots taken on the secondary store during an outage are unknown to the primary once it is back, so a few extra executions may get in at the switch. |
| `apc`, `session`, a custom store without locks | Throws a `LogicException` on first use, in both fail modes. |

### Database store and transactions

A database lock taken inside an open transaction is held until the
transaction commits. If the application wraps requests or jobs in
transactions on the connection the cache uses, give the store a connection
of its own with `lock_connection` in `config/cache.php`, or use another
store.

### Clock skew

`database`, `file` and `array` locks expire by the clock of the application
server that took them. When servers' clocks disagree, slot TTLs shift by the
difference. Redis and Memcached expire keys by their own clock.

### Memcached

`Memcached::add()` answers `false` both when the key exists and when the
server cannot be reached, so a dead Memcached would look like a full pool.
The package checks the client's result code after every refused slot, and
treats anything but "the key already exists" (`RES_NOTSTORED`, or
`RES_DATA_EXISTS` with the binary protocol) as a store failure rather than a
full pool: the limit fails open or closed, and never answers `429` for it.
So:

- behind a `failover` store, a dead Memcached is detected as without it,
  since the check is made on the lock rather than on the store;
- with several servers, a slot refused by a server that cannot be reached is
  a store failure, even when the other servers answer.

Releasing is checked the same way. When Memcached does not delete a slot
for another reason than the slot having expired or changed owner (a result
code other than `RES_SUCCESS` or `RES_NOTFOUND`), the failure is reported as
a `StoreUnavailableException`, and the slot frees itself when its TTL runs
out.

On Memcached and the file store, the framework releases a lock in two steps,
reading its owner then deleting it. If the slot expires between the two, the
release can free a slot another request has just taken. The window is tiny,
and only opens for a slot that has already outlived its TTL.

## Things to know

### Trusted proxies

Guests are counted by `$request->ip()`. Behind a load balancer or a reverse
proxy that the application does not trust, every visitor has the proxy's
address, and all guests share one pool. Configure
[trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies),
or key the limit on something else with `by()`. IPv6 addresses are counted
one by one, not by prefix.

### Long requests and interrupted streams

A request that outlives `expireAfter()` loses its slot: one more execution
may get in, and the late release does nothing. Set `expireAfter()` above the
longest duration you expect, streams included. The same goes for a job,
whose slot follows its timeout by default (see [Slot TTL](#slot-ttl)).

When a client disconnects in the middle of a stream, PHP stops the script and
`terminate()` never runs: the slot frees itself when its TTL runs out. This
is accepted: a safety net that runs at shutdown would pile up under Octane.

### Octane

The package keeps no static state and no per-request data on its middleware.
The manager holds only the limiter closures registered at boot, `Slots` are
built per request, and the slots a request holds live on the request itself.
The default key is set on a copy of the `Slots` a limiter returns, so a
`Slots` instance kept between requests never carries one user's key into
another request. The job middleware holds only its settings, so it can be
serialized with the job (`through()`), and it builds its limit for each job it
runs.

## Coming from laravel-concurrent-limiter

This package replaces
[`largerio/laravel-concurrent-limiter`](https://github.com/largerio/laravel-concurrent-limiter),
rewritten from scratch. Each slot is now a cache lock with its own TTL, rather
than a shared counter: a crashed process frees its slot once the TTL runs
out, a waiting request takes no slot, and a store failure never becomes a
`500`. The scope is smaller too: no adaptive limits, metrics endpoint or
Artisan commands.

The two packages use different namespaces, middleware aliases,
configuration files and cache keys, so routes can move over one at a time.

| laravel-concurrent-limiter | laravel-concurrency-limit |
|---|---|
| `concurrent.limit:10,30,api`: wait up to 30 s, then `503` | A limiter returning `Slots::max(10)`, on `concurrency:api`. Rejects at once with `429`; add `->block(2)` to wait. |
| `LaravelConcurrentLimiter::with(10, 30, 'api')` | `LimitConcurrentRequests::using('api')` |
| `max_parallel`, `max_wait_time` | `Slots::max()`, `->block()` |
| `JobConcurrentLimiter(maxParallel: 5, key: 'stripe-api', releaseAfter: 30)` | `(new ConcurrencyLimited('stripe-api', 5))->shared()->releaseAfter(30)` |
| `shouldRelease: false` | `->dontRelease()` |
| `key_resolver` | `->by()` in the limiter |
| `response_handler`, `error_message` | `->response()` |
| `retry_after` | `->retryAfter()` |
| `ttl_buffer` | `->expireAfter()` |
| `cache_store` | `store` (`CONCURRENCY_LIMIT_STORE`) |
| `on_cache_failure`: `allow` / `reject` | `fail_open`: `true` / `false`, or `->failOpen()` / `->failClosed()` |
| `logging`, `metrics`, Prometheus endpoint | [Events](#events) and your own listener |
| `adaptive` | Removed |
| `concurrent-limiter:status`, `concurrent-limiter:clear` | Removed: slots expire by themselves |
| `ConcurrentLimitAcquired`, `ConcurrentLimitExceeded`, `ConcurrentLimitReleased` | `SlotAcquired`, `ConcurrencyLimitExceeded`, `SlotReleased` |
| `ConcurrentLimitWaitStarted` | Removed |
| `CacheOperationFailed` | `StoreUnavailableException`, passed to `report()` |

The new package requires PHP 8.4 or later and Laravel 13.32 or later. Once
no route uses the old one, remove it and its `config/concurrent-limiter.php`.

## Credits

The job middleware's API comes from
[laravel/framework#62148](https://github.com/laravel/framework/pull/62148)
by [@xurshudyan](https://github.com/xurshudyan), itself modelled on
`WithoutOverlapping`. The pull request was closed with an invitation to ship
it as a package: this is that package, with the HTTP side added.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md) to
report a vulnerability. [CHANGELOG.md](CHANGELOG.md) lists the changes of
each release.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
