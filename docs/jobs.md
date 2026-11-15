# Job middleware

The reference for `ConcurrencyLimited`. The
[README](../README.md#job-middleware) shows a complete job, and why it sets
`$tries`, `retryUntil()` and `$maxExceptions`.

## Constructor and methods

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
| `failOpen()` / `failClosed()` | Behavior when the cache store fails, as for [HTTP](stores.md#when-the-cache-store-fails). Fail-closed releases the job. |

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

## Slot TTL

A job that times out is killed by the worker: nothing runs after it, and only
the slot's TTL frees the slot. So the TTL follows the job's timeout: the
job's `$timeout` plus 10 seconds, or 70 seconds without one (the worker's
default `--timeout` of 60 seconds, plus margin). A `$timeout` of 0, which
turns the timeout off, counts as none. **If your workers run with a longer
`--timeout` and the job sets no `$timeout`, set `$timeout` on the job or call
`expireAfter()`**, or a long job may lose its slot while it still runs.

## Jobs that cannot be released

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
  request, it is reported, then rendered as the `concurrency` middleware
  would answer: a `429` with `Retry-After`, or a `503` when the store failed
  and the job fails closed, never a `500`. The response shows neither the
  pool nor the key. To answer otherwise, catch it:

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

## Differences from laravel/framework#62148

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
