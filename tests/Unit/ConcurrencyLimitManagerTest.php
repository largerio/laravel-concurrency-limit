<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Largerio\ConcurrencyLimit\ConcurrencyLimitManager;
use Largerio\ConcurrencyLimit\Slots;

describe('named limiters', function (): void {
    it('resolves a limiter to its limits for the request', function (): void {
        $manager = new ConcurrencyLimitManager;
        $request = Request::create('/reports');
        $given = null;

        $manager->for('reports', function (Request $request) use (&$given): Slots {
            $given = $request;

            return Slots::max(2);
        });

        [$limiter, $limits] = $manager->resolve($request, 'reports');

        expect($limiter)->toBe('reports')
            ->and($limits)->toHaveCount(1)
            ->and($limits[0]->max)->toBe(2)
            ->and($given)->toBe($request);
    });

    it('builds the limits again for every request', function (): void {
        $manager = new ConcurrencyLimitManager;
        $manager->for('reports', fn (): Slots => Slots::max(2));

        [, [$first]] = $manager->resolve(Request::create('/'), 'reports');
        [, [$second]] = $manager->resolve(Request::create('/'), 'reports');

        expect($first)->not->toBe($second);
    });

    it('accepts several limits, as a list', function (): void {
        $manager = new ConcurrencyLimitManager;
        $manager->for('exports', fn (): array => [
            'user' => Slots::max(2)->by('user:1'),
            'team' => Slots::max(10)->by('team:1'),
        ]);

        [, $limits] = $manager->resolve(Request::create('/'), 'exports');

        expect($limits)->toBeList()
            ->and(array_map(fn (Slots $slots): ?string => $slots->key, $limits))->toBe(['user:1', 'team:1']);
    });

    it('means no limit when the limiter returns null or no limits', function (mixed $limits): void {
        $manager = new ConcurrencyLimitManager;
        $manager->for('ai', fn (): mixed => $limits);

        expect($manager->resolve(Request::create('/'), 'ai'))->toBe(['ai', []]);
    })->with(['null' => [null], 'an empty array' => [[]]]);

    it('refuses a limiter that returns something else', function (mixed $limits): void {
        $manager = new ConcurrencyLimitManager;
        $manager->for('api', fn (): mixed => $limits);

        expect(fn (): array => $manager->resolve(Request::create('/'), 'api'))
            ->toThrow(InvalidArgumentException::class, 'The [api] concurrency limiter must return Slots, an array of Slots or null.');
    })->with([
        'a rate limit' => [fn (): Limit => Limit::perMinute(60)],
        'an integer' => [10],
        'an array with something else' => [fn (): array => [Slots::max(1), 'user:1']],
    ]);

    it('replaces a limiter defined twice, and is fluent', function (): void {
        $manager = new ConcurrencyLimitManager;

        $returned = $manager->for('api', fn (): Slots => Slots::max(1))
            ->for('api', fn (): Slots => Slots::max(5));

        [, [$slots]] = $manager->resolve(Request::create('/'), 'api');

        expect($returned)->toBe($manager)
            ->and($slots->max)->toBe(5);
    });

    it('throws when the limiter is not defined', function (): void {
        expect(fn (): array => new ConcurrencyLimitManager()->resolve(Request::create('/'), 'reports'))
            ->toThrow(InvalidArgumentException::class, 'Concurrency limiter [reports] is not defined.');
    });
});

describe('inline form', function (): void {
    it('reads a number of slots, counted in the default pool', function (): void {
        [$limiter, $limits] = new ConcurrencyLimitManager()->resolve(Request::create('/'), '2');

        expect($limiter)->toBe('default')
            ->and($limits)->toHaveCount(1)
            ->and($limits[0]->max)->toBe(2)
            ->and($limits[0]->key)->toBeNull();
    });

    it('counts the slots in the pool named next', function (): void {
        [$limiter, [$slots]] = new ConcurrencyLimitManager()->resolve(Request::create('/'), '3', 'imports');

        expect($limiter)->toBe('imports')
            ->and($slots->max)->toBe(3);
    });

    it('accepts zero, which always rejects', function (): void {
        [, [$slots]] = new ConcurrencyLimitManager()->resolve(Request::create('/'), '0');

        expect($slots->max)->toBe(0);
    });

    it('prefers a limiter registered under a numeric name', function (): void {
        $manager = new ConcurrencyLimitManager;
        $manager->for('5', fn (): Slots => Slots::max(1));

        [$limiter, [$slots]] = $manager->resolve(Request::create('/'), '5', 'imports');

        expect($limiter)->toBe('5')
            ->and($slots->max)->toBe(1);
    });

    it('only reads a whole number of slots', function (string $name): void {
        expect(fn (): array => new ConcurrencyLimitManager()->resolve(Request::create('/'), $name))
            ->toThrow(InvalidArgumentException::class, "Concurrency limiter [{$name}] is not defined.");
    })->with(['-1', '2.5', '1e3', '']);
});
