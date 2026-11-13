<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests;

use Largerio\ConcurrencyLimit\ConcurrencyLimitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ConcurrencyLimitServiceProvider::class,
        ];
    }
}
