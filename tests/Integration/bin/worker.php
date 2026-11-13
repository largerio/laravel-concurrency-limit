<?php

declare(strict_types=1);

/*
 * One process of the multi-process integration suite, driven by
 * Largerio\ConcurrencyLimit\Tests\Integration\Support\Workers.
 *
 * It sends its messages to the driver on file descriptor 3, one JSON line
 * each, and leaves stdout and stderr to anything else, such as an exception
 * that Laravel's console handler renders on stdout.
 *
 * It boots an application on the store under test and says it is ready,
 * waits for the start time the driver writes on stdin, then does its work
 * under a slot, in one of three ways ("via"):
 *
 * - pool: takes the slot from the SlotPool, works and gives it back;
 * - http: sends one request through the HTTP kernel and the concurrency
 *   middleware, to a route that works;
 * - stream: the same, to a route whose streamed response works while its
 *   body is sent, between the kernel's handle() and terminate().
 *
 * It ends with one result whose timestamps are taken inside the critical
 * section. With "hold", it announces the slot and waits to be killed.
 */

namespace Largerio\ConcurrencyLimit\Tests\Integration;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Largerio\ConcurrencyLimit\ConcurrencyLimitServiceProvider;
use Largerio\ConcurrencyLimit\Events\SlotAcquired;
use Largerio\ConcurrencyLimit\Exceptions\ConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\Facades\ConcurrencyLimit;
use Largerio\ConcurrencyLimit\SlotPool;
use Largerio\ConcurrencyLimit\Slots;
use Orchestra\Testbench\Foundation\Application;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

require __DIR__.'/../../../vendor/autoload.php';

$messages = fopen('php://fd/3', 'w');

if ($messages === false) {
    throw new RuntimeException('Start this worker through Workers: it reports on file descriptor 3.');
}

/** @param array<string, mixed> $message */
$say = function (array $message) use ($messages): void {
    fwrite($messages, json_encode($message, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
    fflush($messages);
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
 *     via?: 'pool'|'http'|'stream',
 * } $options
 */
$options = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
$via = $options['via'] ?? 'pool';

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(ConcurrencyLimitServiceProvider::class);

config($options['config']);

$exceptions = Exceptions::fake();

$limit = function () use ($options): Slots {
    $slots = Slots::max($options['max'])
        ->by($options['key'])
        ->block($options['block'])
        ->expireAfter($options['expire_after']);

    return match ($options['fail_open']) {
        true => $slots->failOpen(),
        false => $slots->failClosed(),
        null => $slots,
    };
};

$result = ['arrive' => null, 'start' => null, 'end' => null, 'leased' => false, 'status' => 200, 'retry_after' => null, 'error' => null];

$work = function () use (&$result, $options, $say): void {
    $result['start'] = microtime(true);

    if ($options['hold']) {
        $say(['held' => $result['start']]);

        sleep(600);
    }

    usleep($options['work_ms'] * 1000);

    $result['end'] = microtime(true);
};

$pool = $app->make(SlotPool::class);
$kernel = $app->make(Kernel::class);

Event::listen(SlotAcquired::class, function () use (&$result): void {
    $result['leased'] = true;
});

ConcurrencyLimit::for($options['limiter'], $limit);

Route::get('/work', function () use ($work, $via): Response|string {
    if ($via === 'stream') {
        return response()->stream($work);
    }

    $work();

    return 'done';
})->middleware("concurrency:{$options['limiter']}");

$say(['ready' => true]);

$startAt = (float) fgets(STDIN);

while (microtime(true) < $startAt) {
    usleep(100);
}

$result['arrive'] = microtime(true);

if ($via === 'pool') {
    $leases = null;

    try {
        $leases = $pool->acquire($options['limiter'], [$limit()]);
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
        $work();

        $pool->release($leases);
    }
}

if ($via !== 'pool') {
    $response = $kernel->handle($request = Request::create('/work'));

    // Response::send() runs a stream between handle() and terminate(); the body is discarded.
    ob_start(fn (): string => '');
    $response->sendContent();
    ob_end_clean();

    $kernel->terminate($request, $response);

    $result['status'] = $response->getStatusCode();
    $result['retry_after'] = $response->headers->get('Retry-After');
}

$result['done'] = microtime(true);
$result['reports'] = array_map(fn (Throwable $exception): array => [
    'class' => $exception::class,
    'message' => $exception->getMessage(),
    'previous' => $exception->getPrevious() instanceof Throwable ? $exception->getPrevious()::class : null,
], $exceptions->reported());

$say($result);
