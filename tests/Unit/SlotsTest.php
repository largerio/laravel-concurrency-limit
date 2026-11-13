<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Largerio\ConcurrencyLimit\Slots;

it('has sensible defaults', function (): void {
    $slots = Slots::max(3);

    expect($slots->max)->toBe(3)
        ->and($slots->key)->toBeNull()
        ->and($slots->block)->toBe(0.0)
        ->and($slots->expireAfter)->toBe(60)
        ->and($slots->retryAfter)->toBe(1)
        ->and($slots->responseCallback)->toBeNull()
        ->and($slots->failOpen)->toBeNull();
});

it('is configured fluently on the same instance', function (): void {
    $response = fn (Request $request, array $headers): string => 'busy';

    $slots = Slots::max(2);

    $configured = $slots
        ->by('team:1')
        ->block(1.5)
        ->expireAfter(120)
        ->retryAfter(5)
        ->response($response)
        ->failClosed();

    expect($configured)->toBe($slots)
        ->and($slots->key)->toBe('team:1')
        ->and($slots->block)->toBe(1.5)
        ->and($slots->expireAfter)->toBe(120)
        ->and($slots->retryAfter)->toBe(5)
        ->and($slots->responseCallback)->toBe($response)
        ->and($slots->failOpen)->toBeFalse()
        ->and($slots->failOpen()->failOpen)->toBeTrue();
});

it('accepts an integer key and a whole number of seconds to block', function (): void {
    $slots = Slots::max(1)->by(42)->block(2);

    expect($slots->key)->toBe('42')
        ->and($slots->block)->toBe(2.0);
});

it('accepts a limit of zero, which always rejects', function (): void {
    expect(Slots::max(0)->max)->toBe(0);
});

it('accepts a Retry-After of zero', function (): void {
    expect(Slots::max(1)->retryAfter(0)->retryAfter)->toBe(0);
});

it('rejects invalid settings', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'negative max' => [fn (): Slots => Slots::max(-1), 'The maximum number of slots must be at least 0, got -1.'],
    'zero expiry' => [fn (): Slots => Slots::max(1)->expireAfter(0), 'Slots must expire after at least 1 second, got 0.'],
    'negative retry after' => [fn (): Slots => Slots::max(1)->retryAfter(-1), 'The Retry-After delay must be at least 0 seconds, got -1.'],
    'negative block' => [fn (): Slots => Slots::max(1)->block(-0.5), 'The time to wait for a slot must be a finite number of seconds of at least 0, got -0.5.'],
    'infinite block' => [fn (): Slots => Slots::max(1)->block(INF), 'The time to wait for a slot must be a finite number of seconds of at least 0, got INF.'],
    'NaN block' => [fn (): Slots => Slots::max(1)->block(NAN), 'The time to wait for a slot must be a finite number of seconds of at least 0, got NAN.'],
]);

it('cannot be modified from outside', function (): void {
    $slots = Slots::max(1);

    expect(fn (): int => $slots->max = 5)->toThrow(Error::class, 'Cannot modify private(set) property');
});
