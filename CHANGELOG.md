# Changelog

Notable changes to `largerio/laravel-concurrency-limit`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The first release. It replaces `largerio/laravel-concurrent-limiter`, written
again from scratch: see
[Coming from laravel-concurrent-limiter](README.md#coming-from-laravel-concurrent-limiter).

### Added

- A `concurrency` route middleware that caps the requests in flight per user, tenant or key, with named limiters registered through `ConcurrencyLimit::for()` (`concurrency:api`) and an inline form (`concurrency:2`, `concurrency:2,imports`).
- `Slots`, the limit a limiter returns: `Slots::max()` with `by()`, `block()`, `expireAfter()`, `retryAfter()`, `response()`, `failOpen()` and `failClosed()`. A limiter may also return several limits, granted all or none, or `null` for no limit.
- A default key: the authenticated user, told apart by model class, else the client's IP address.
- An opt-in wait for a slot (`block()`), during which a request holds no slot.
- Rejections answer `429 Too Many Requests` with `Retry-After` (`ConcurrencyLimitExceededException`), or the limit's own `response()`.
- Streamed and file responses keep their slot until the body is sent.
- When the cache store fails, the limit fails open by default (the request or job runs without a slot) or closed on demand (`503` for a request, a release for a job). Either way the failure is reported as a `StoreUnavailableException`, never turned into a `500`.
- `ConcurrencyLimited`, a job middleware that runs at most N jobs at once per key. Its constructor and fluent methods match those proposed in laravel/framework#62148; a slot always expires (by default just after the job's timeout), and a job without a free slot is released with a delay and jitter, rather than at once.
- Events: `SlotAcquired`, `SlotReleased` and `ConcurrencyLimitExceeded`.
- Works on any cache store with atomic locks. Requires PHP 8.4 or later and Laravel 13.

[Unreleased]: https://github.com/largerio/laravel-concurrency-limit/commits/main
