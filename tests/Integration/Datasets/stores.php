<?php

declare(strict_types=1);

use Largerio\ConcurrencyLimit\Tests\Integration\Support\StoreUnderTest;

dataset('stores', fn (): array => [
    'redis (phpredis)' => [StoreUnderTest::redis('phpredis')],
    'redis (predis)' => [StoreUnderTest::redis('predis')],
    'memcached' => [StoreUnderTest::memcached()],
    'database (sqlite, wal)' => [StoreUnderTest::database()],
    'file' => [StoreUnderTest::file()],
]);
