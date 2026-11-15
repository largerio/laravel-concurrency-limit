# Contributing

Thanks for your interest in the package. This guide covers setting up the
project, what a change needs before it's merged, and how a release is made.

## Prerequisites

Everything runs in Docker, through the `Makefile`: the host needs **Docker**
(with Compose v2) and **make**, and nothing else. No PHP, Composer, Redis or
Memcached on your machine.

## Getting started

```bash
git clone https://github.com/largerio/laravel-concurrency-limit.git
cd laravel-concurrency-limit
make build     # the development image (PHP 8.5 by default)
make install   # composer install, in the container
make check     # lint + analyse + test
```

`make test` runs Pest in the `app` service of `docker-compose.dev.yml`, next
to the `redis` and `memcached` services, so the whole suite runs locally,
integration tests included.

The containers run as your user, without capabilities or privilege
escalation, on a read-only root filesystem, and publish no port. Redis and
Memcached sit on an internal network with no route to the outside.

### Make targets

| Target | What it does |
|---|---|
| `make build` | Builds the development image |
| `make install` | Installs the dependencies |
| `make lint` | `composer validate --strict`, Pint and Rector in dry-run |
| `make format` | Applies Rector, then Pint |
| `make analyse` | PHPStan |
| `make test` | Pest, every group |
| `make coverage` | Pest with coverage; fails below 100% of the lines of `src/` |
| `make check` | `lint`, `analyse` and `test`: the gate before every commit |
| `make matrix` | The test suite on every supported PHP version |
| `make secrets` | gitleaks over the git history |
| `make shell` | A shell in the development container |
| `make down` | Stops the services |

Two variables work with any target:

```bash
make test ARGS="--filter=Slots"   # extra arguments for Pest
make test PHP_VERSION=8.4         # another PHP version (8.4 or 8.5)
```

The package ships no `composer.lock` (`"lock": false` in `composer.json`), so
every install resolves the newest versions in range, as an application's
would. `make matrix` installs each PHP version's dependencies in
`build/matrix/<version>`, and leaves `vendor/` alone.

## Making a change

1. Create a branch from `main`.
2. Make your change, with tests. New behavior without tests will be asked
   for them.
3. Run `make check`. It must pass before every commit: CI runs the same
   checks, and more (see [CI](#ci)).
4. Run `make coverage` when you touch `src/`: every line of `src/` is
   covered, and CI fails below 100%.
5. Add an entry under `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md) for any
   change a user of the package can notice (see [Changelog](#changelog)).
6. Update [README.md](README.md) and the reference in `docs/` when you
   change the public API, a default or a documented trade-off.
7. Commit with a clear message (see [Commit messages](#commit-messages)),
   and open a pull request.

## Code style

- `declare(strict_types=1)` in every PHP file.
- [Pint](https://laravel.com/docs/pint) with the Laravel preset, and
  [Rector](https://getrector.com/) for the PHP version sets, dead code,
  code quality, type declarations, privatization and early returns.
  `make format` applies both.
- [PHPStan](https://phpstan.org/) at level `max`, with bleeding edge,
  Larastan, the strict rules and the deprecation rules, over `src/`,
  `config/` and the plain PHP of `tests/`. Pest test files are left out:
  Pest's API is resolved at runtime and cannot be typed.
- No PHPStan baseline. An ignored error names its identifier and its path,
  and a comment says why.
- Early returns rather than `else`, typed and promoted constructor
  properties, string interpolation rather than concatenation, and few
  comments: a comment says why, the code says what.

## Design rules

The test suite enforces some of these (`tests/ArchTest.php`); keep to the
others in review.

- `SlotPool` is the only class that touches the cache.
- Nothing in `src/` uses `Illuminate\Cache\Limiters`: the package keeps its
  own slot pool on the framework's cache locks.
- Events are `final readonly` classes.
- No static state, and no per-request data on a middleware: leases live on
  the request, so the package is safe under Octane.
- Application code never runs inside the `try` that acquires slots, so an
  exception it throws is never taken for a rejection.
- A failure of the cache store never produces an HTTP 500: it is reported,
  and the limit fails open or closed.

## Tests

Pest, on [Testbench](https://packages.tools/testbench).

- **Unit and feature tests** (`tests/Unit`, `tests/Feature`) run on the
  `array` cache store with `Sleep::fake()`, in one process, and need no
  service.
- **Integration tests** (`tests/Integration`, Pest group `integration`)
  start real PHP processes that compete for the same slots, against Redis
  (phpredis and predis), Memcached, the database store (SQLite) and the file
  store. They assert on timestamps taken inside the critical section, never
  on durations, so they hold on a slow machine.

```bash
make test ARGS="--exclude-group=integration"   # without the stores
make test ARGS="--group=integration"           # the integration group only
```

In CI, the `test` job runs everything but the integration group, on PHP 8.4
and 8.5, with the lowest and the newest dependencies Composer installs. The
`integration` job runs the group against Redis and Memcached service
containers, the same images as `docker-compose.dev.yml`, once on the newest
dependencies and once on Laravel 13.32, the lower bound of `composer.json`.
The `coverage` job runs the whole suite.

With the lowest dependencies, Composer installs Laravel 13.32.0, the lower
bound itself. Composer refuses a release that has a known security advisory,
though: should one be published against a lower bound, the lowest versions
tested would move above it.

## CI

Every pull request runs:

- `lint`, `analyse`, `test`, `integration` and `coverage`, as above;
- `package`: `composer validate --strict`, and a check that the archive
  Composer installs holds only `src/`, `config/`, `docs/`, `composer.json`,
  `README.md`, `CHANGELOG.md` and `LICENSE.md` (anything else is marked
  `export-ignore` in `.gitattributes`);
- `audit`: `composer audit`;
- `secrets`: gitleaks over the git history;
- `workflows`: actionlint.

Workflows grant no permission by default and each job only what it needs,
actions are pinned by commit SHA, and checkouts keep no credentials. A
scheduled workflow audits the dependencies and scans for secrets every week,
and opens an issue when either fails.

## Changelog

[CHANGELOG.md](CHANGELOG.md) follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). New entries go
under `## [Unreleased]`, in one of these sections: `Added`, `Changed`,
`Deprecated`, `Removed`, `Fixed`, `Security`. Write for someone who uses the
package: what changes for them, not how the code changed.

A release turns `[Unreleased]` into a section headed with its version and
date:

```markdown
## [1.2.0] - 2027-01-15
```

## Commit messages

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add a per-tenant default key
fix: release slots when the response is a binary file
test: cover the wait loop under Sleep::fake()
docs: document the failover store
```

## Dependencies

- Dependabot proposes no Composer package or action less than three days
  old. Its Composer patch and minor PRs, and its bumps of the development
  images (`Dockerfile.dev`, `docker-compose.dev.yml`), are labeled
  `auto-merge-48h` and merged once they're 48 hours old and green. Majors
  and GitHub Actions updates are reviewed and merged by hand: an action runs
  with the tokens and secrets of the workflows that use it.
- Every image is pinned by tag and digest: update both together.

## Releasing

For maintainers:

1. On `main`, move the `[Unreleased]` entries under a new
   `## [X.Y.Z] - YYYY-MM-DD` section, and commit.
2. Tag that commit and push the tag:

   ```bash
   git tag vX.Y.Z && git push origin vX.Y.Z
   ```

Packagist picks the tag up through its webhook. The `Release` workflow
checks that the tag is on `main` and that `CHANGELOG.md` has a dated,
non-empty section for its version, and writes that section to the run's
summary, for the GitHub release notes.

## Security

Please report vulnerabilities privately: see
[SECURITY.md](SECURITY.md#reporting-a-vulnerability).

## License

By contributing, you agree that your contributions will be licensed under the
[MIT License](LICENSE.md).
