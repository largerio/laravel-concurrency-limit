# Security policy

## Reporting a vulnerability

If you find a security vulnerability in this package, please report it
privately, through
[GitHub Security Advisories](https://github.com/largerio/laravel-concurrency-limit/security/advisories/new),
or by email to louis@larger.io. Please don't open a public issue.

We acknowledge reports within 48 hours, and aim to ship a fix for critical
issues within 7 days.

## Supported versions

Fixes ship in new releases of the latest major version, and older releases
aren't patched. [CHANGELOG.md](CHANGELOG.md) lists what each release changes.

`largerio/laravel-concurrent-limiter`, which this package replaces, gets no
fixes.

## Scope

This package caps how many executions one user, tenant or key runs at once.
It is a fairness tool, not a security control: protecting a server from
overload or from a denial of service belongs to the infrastructure (web
server, proxy, process manager), and the trade-offs the README documents can
let one extra execution through.

In scope:

- A client taking or freeing slots in a pool that isn't its own
- A release that frees a slot held by another execution
- A failure of the cache store that turns into an HTTP 500, or that exposes
  details of the store in a response
- A limit bypassed in a way the README does not document

Out of scope, because the README documents them:

- An extra execution admitted when a slot outlives its TTL, during a failover
  between cache stores, or while the store is down and the limit fails open
- Anonymous clients sharing one pool behind a proxy the application does not
  trust ([trusted proxies](README.md#trusted-proxies))
- Keys that can be guessed from the cache: they are hashed with xxh128, which
  keeps them out of the cache in clear text but is not a cryptographic hash
