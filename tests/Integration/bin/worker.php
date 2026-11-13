<?php

declare(strict_types=1);

/*
 * One process of the multi-process integration suite, driven by
 * Largerio\ConcurrencyLimit\Tests\Integration\Support\Workers.
 *
 * It boots an application on the store under test and prints a "ready" line,
 * waits for the start time the driver writes on stdin, then takes a slot,
 * works and gives the slot back, as a request going through the middleware
 * would. It ends with one JSON line whose timestamps are taken inside the
 * critical section. With "hold", it announces the slot and waits to be killed.
 */

namespace Largerio\ConcurrencyLimit\Tests\Integration;

use Illuminate\Support\Facades\Exceptions;
use Largerio\ConcurrencyLimit\ConcurrencyLimitServiceProvider;
use Largerio\ConcurrencyLimit\Exceptions\ConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\SlotPool;
use Largerio\ConcurrencyLimit\Slots;
use Orchestra\Testbench\Foundation\Application;
use Throwable;

require __DIR__.'/../../../vendor/autoload.php';

ini_set('display_errors', 'stderr');

/** @param array<string, mixed> $line */
$say = function (array $line): void {
    fwrite(STDOUT, json_encode($line, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
};

/**
 * @var array{
 *     config: array<string, mixed>,
 *     limiter: string,
 *     key: string,
 *     max: int,
 *     block: float,
 *     expire_after: int,
 *     fail_open: ?bool,
 *     work_ms: int,
 *     hold: bool,
 * } $options
 */
$options = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(ConcurrencyLimitServiceProvider::class);

config($options['config']);

$exceptions = Exceptions::fake();
$pool = $app->make(SlotPool::class);

$slots = Slots::max($options['max'])
    ->by($options['key'])
    ->block($options['block'])
    ->expireAfter($options['expire_after']);

match ($options['fail_open']) {
    true => $slots->failOpen(),
    false => $slots->failClosed(),
    null => $slots,
};

$say(['ready' => true]);

$startAt = (float) fgets(STDIN);

while (microtime(true) < $startAt) {
    usleep(100);
}

$result = ['arrive' => microtime(true), 'start' => null, 'end' => null, 'leased' => false, 'status' => 200, 'error' => null];
$leases = null;

try {
    $leases = $pool->acquire($options['limiter'], [$slots]);
} catch (ConcurrencyLimitExceededException) {
    $result['status'] = 429;
} catch (StoreUnavailableException) {
    $result['status'] = 503;
} catch (Throwable $exception) {
    $result['status'] = 500;
    $class = $exception::class;
    $result['error'] = "{$class}: {$exception->getMessage()}";
}

if ($leases !== null) {
    $result['start'] = microtime(true);
    $result['leased'] = $leases !== [];

    if ($options['hold']) {
        $say(['held' => $result['start']]);

        sleep(600);
    }

    usleep($options['work_ms'] * 1000);

    $result['end'] = microtime(true);

    $pool->release($leases);
}

$result['done'] = microtime(true);
$result['reports'] = array_map(fn (Throwable $exception): array => [
    'class' => $exception::class,
    'message' => $exception->getMessage(),
    'previous' => $exception->getPrevious() instanceof Throwable ? $exception->getPrevious()::class : null,
], $exceptions->reported());

$say($result);
