<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Events;

/** Dispatched once per slot given back, even when the store failed to delete it (its expiry frees it). */
final readonly class SlotReleased
{
    public function __construct(
        public string $limiter,
        public string $key,
        public int $max,
        /** Seconds the slot was held. */
        public float $held,
    ) {}
}
