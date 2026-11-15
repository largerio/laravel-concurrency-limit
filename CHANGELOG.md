# Changelog

Notable changes to `largerio/laravel-concurrency-limit`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-11-13

The first release.

### Added

- A `concurrency` route middleware that caps the requests in flight per user, tenant or key, with named limiters registered through `ConcurrencyLimit::for()` (`concurrency:api`) and an inline form (`concurrency:2`, `concurrency:2,imports`). The names `job` and `job:*` are reserved for the job middleware.
- `Slots`, the limit a limiter returns: `Slots::max()` with `by()`, `block()`, `expireAfter()`, `retryAfter()`, `response()`, `failOpen()` and `failClosed()`. A limiter may also return several limits, granted all or none, or `null` for no limit.
- A default key: the authenticated user, told apart by model class, else the client's IP address. Custom keys can be any string, binary data included.
- The middleware runs after authentication, `auth.basic` included, and rate limiting, and before route model binding, whatever the order on the route.
- An opt-in wait for a slot (`block()`), during which a request holds no slot. A limit of `Slots::max(0)` rejects at once, without waiting.
- Rejections answer `429 Too Many Requests` with `Retry-After` (`ConcurrencyLimitExceededException`), or the limit's own `response()`.
- Streamed responses keep their slot until their body ends, even when it throws, and file responses until they are sent. A streamed body written as a generator stays a generator, for Octane.
- When the cache store fails, the limit fails open by default (the request or job runs without a slot) or closed on demand (`503` for a request, a release for a job). Either way the failure is reported as a `StoreUnavailableException`, and the HTTP middleware never turns it into a `500`.
- On Memcached, which answers a refused slot the same way whether the slot is taken or the server is down, an unreachable server is a store failure, not a full pool, with several servers and behind a `failover` store too. A release Memcached fails to perform is reported.
- `ConcurrencyLimited`, a job middleware that runs at most N jobs at once per key. Its constructor and fluent methods match those proposed in laravel/framework#62148; a slot always expires (by default just after the job's timeout), and a job without a free slot is released with a delay and jitter, rather than at once.
- `JobConcurrencyLimitExceededException`, a `RuntimeException` rather than an HTTP exception, so that the exception handler reports it, thrown when a job gets no slot and cannot be released: a job the sync driver runs (`dispatchSync()`, the `sync`, `deferred` and `background` connections, `dispatchAfterResponse()`) or a job without `InteractsWithQueue`, even with `dontRelease()`. It carries the pool (`$limiter`) and the limit that refused (`$slots`); its previous exception is the rejection, or the store failure when the job fails closed. Left uncaught during a request, it renders as a `429`, or a `503` when the store failed, never a `500`.
- Events: `SlotAcquired`, `SlotReleased` and `ConcurrencyLimitExceeded`. `SlotReleased` balances `SlotAcquired`, even when a `SlotAcquired` listener throws.
- Works on any cache store with atomic locks. Requires PHP 8.4 or later and `laravel/framework` 13.32 or later.

[Unreleased]: https://github.com/largerio/laravel-concurrency-limit/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/largerio/laravel-concurrency-limit/releases/tag/v1.0.0
