# Cache stores

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

## When the cache store fails

```php
Slots::max(5)->failClosed();   // per limiter

// config/concurrency-limit.php: 'fail_open' => true   (the global default)
```

- **Fail-open** (the default): the request or job runs without a slot.
- **Fail-closed**: a request gets a `503 Service Unavailable` with
  `Retry-After` (the largest `retryAfter()` among the request's limits); a
  job is released back to the queue, as when no slot is free, and a job that
  cannot be released throws (see
  [Jobs that cannot be released](jobs.md#jobs-that-cannot-be-released)).

With [multiple limits](http.md#multiple-limits), one limit that fails closed is
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

## Database store and transactions

A database lock taken inside an open transaction is held until the
transaction commits. If the application wraps requests or jobs in
transactions on the connection the cache uses, give the store a connection
of its own with `lock_connection` in `config/cache.php`, or use another
store.

## Clock skew

`database`, `file` and `array` locks expire by the clock of the application
server that took them. When servers' clocks disagree, slot TTLs shift by the
difference. Redis and Memcached expire keys by their own clock.

## Memcached

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
