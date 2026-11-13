<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Override;
use RuntimeException;

/**
 * An array store whose locks are acquired normally but throw on release,
 * like a lock driver whose server went away while the work was running.
 */
final class ThrowingOnReleaseStore extends ArrayStore
{
    #[Override]
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new class($this, $name, $seconds, $owner) extends ArrayLock
        {
            #[Override]
            public function release(): never
            {
                throw new RuntimeException('Connection lost');
            }
        };
    }
}
