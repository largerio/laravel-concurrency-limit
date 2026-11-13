<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Events;

/** Dispatched once per slot granted, after every limit of the request granted one. */
final readonly class SlotAcquired
{
    public function __construct(
        public string $limiter,
        public string $key,
        public int $max,
        /** Seconds spent waiting for a slot. */
        public float $waited,
    ) {}
}
