# HTTP middleware

The reference for the `concurrency` middleware. The
[README](../README.md#http-middleware) has the overview and the table of
[`Slots`](../README.md#slots) methods.

## Named limiters

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
pools of the [job middleware](jobs.md#job-middleware): `ConcurrencyLimit::for()`
throws an `InvalidArgumentException` for them.

## Inline form

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

## Keys

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

## Multiple limits

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

## Waiting for a slot

```php
ConcurrencyLimit::for('checkout', fn () => Slots::max(1)->block(2));
```

By default a request is rejected immediately. With `block()`, it polls for a free
slot, every 100 ms give or take 50% (the randomness keeps waiting requests
from polling in step), until the time is up. A waiting request holds a PHP
worker, so keep this to a couple of seconds.

Waiting requests hold no slot and reserve nothing, and they are not served in
arrival order: the next free slot goes to whichever request asks first. With
several limits, the wait is the smallest `block()` among them, and there is
none when one of them is `Slots::max(0)`, which never grants a slot.

## Rejection response

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

## Middleware order

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

## When a slot is released

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

## Trusted proxies

Guests are counted by `$request->ip()`. Behind a load balancer or a reverse
proxy that the application does not trust, every visitor has the proxy's
address, and all guests share one pool. Configure
[trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies),
or key the limit on something else with `by()`. IPv6 addresses are counted
one by one, not by prefix.

## Long requests and interrupted streams

A request that outlives `expireAfter()` loses its slot: one more execution
may get in, and the late release does nothing. Set `expireAfter()` above the
longest duration you expect, streams included. The same goes for a job,
whose slot follows its timeout by default (see [Slot TTL](jobs.md#slot-ttl)).

When a client disconnects in the middle of a stream, PHP stops the script and
`terminate()` never runs: the slot frees itself when its TTL runs out. This
is accepted: a safety net that runs at shutdown would pile up under Octane.
