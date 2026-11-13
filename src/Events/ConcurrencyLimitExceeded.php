<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Events;

/** Dispatched once per rejected acquisition, for the limit that refused. */
final readonly class ConcurrencyLimitExceeded
{
    public function __construct(
        public string $limiter,
        public string $key,
        public int $max,
        /** Seconds spent waiting for a slot. */
        public float $waited,
    ) {}
}
