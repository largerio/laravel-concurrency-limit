<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Override;
use RuntimeException;

/**
 * An array store whose locks are taken, then throw, like a Redis reply lost
 * after SET NX ran on the server.
 */
final class ThrowingAfterAcquireStore extends ArrayStore
{
    #[Override]
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new class($this, $name, $seconds, $owner) extends ArrayLock
        {
            #[Override]
            public function acquire(): never
            {
                parent::acquire();

                throw new RuntimeException('Read error on connection');
            }
        };
    }
}
