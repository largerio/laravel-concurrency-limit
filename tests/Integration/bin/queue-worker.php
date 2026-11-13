<?php

declare(strict_types=1);

/*
 * One `queue:work` process of the multi-process job scenario, driven by
 * Largerio\ConcurrencyLimit\Tests\Integration\Support\QueueWorkers.
 *
 * It boots an application on the store and the queue under test, prints a
 * "ready" line, then works the queue until SIGTERM, as a deploy stops a
 * worker, or until --max-time runs out.
 */

namespace Largerio\ConcurrencyLimit\Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use Largerio\ConcurrencyLimit\ConcurrencyLimitServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../../vendor/autoload.php';

ini_set('display_errors', 'stderr');

/**
 * @var array{
 *     config: array<string, mixed>,
 *     connection: string,
 *     queue: string,
 *     max_time: int,
 * } $options
 */
$options = json_decode($argv[1] ?? '', true, flags: JSON_THROW_ON_ERROR);

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->register(ConcurrencyLimitServiceProvider::class);

config($options['config']);

fwrite(STDOUT, "ready\n");
fflush(STDOUT);

$app->make(Kernel::class)->call('queue:work', [
    'connection' => $options['connection'],
    '--queue' => $options['queue'],
    '--sleep' => 0.1,
    '--max-time' => $options['max_time'],
]);
