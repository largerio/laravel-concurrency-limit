<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Override;
use RuntimeException;

/**
 * An array store whose locks throw on acquire, like a lock driver whose
 * server went away. The first `$workingLocks` locks still behave normally.
 */
final class ThrowingStore extends ArrayStore
{
    public function __construct(private int $workingLocks = 0)
    {
        parent::__construct();
    }

    #[Override]
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        if ($this->workingLocks-- > 0) {
            return parent::lock($name, $seconds, $owner);
        }

        return new class($this, $name, $seconds, $owner) extends ArrayLock
        {
            #[Override]
            public function acquire(): never
            {
                throw new RuntimeException('Connection refused');
            }
        };
    }
}
