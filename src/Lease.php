<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit;

use Illuminate\Contracts\Cache\Lock;

/** One slot held for a limiter and key, until given back to the SlotPool. */
final readonly class Lease
{
    public function __construct(
        public Lock $lock,
        public string $limiter,
        public string $key,
        public int $max,
        public float $acquiredAt,
    ) {}
}
