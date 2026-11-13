<?php

declare(strict_types=1);

/*
 * One `queue:work` process of the multi-process job scenario, driven by
 * Largerio\ConcurrencyLimit\Tests\Integration\Support\QueueWorkers.
 *
 * It boots an application on the store and the queue under test, says it is
 * ready on file descriptor 3, then works the queue until SIGTERM, as a deploy
 * stops a worker, or until --max-time runs out, and exits with the status of
 * queue:work. The command writes to a buffer: stdout and stderr only receive
 * what nobody expects, such as an exception that Laravel's console handler
 * renders on stdout.
 */

namespace Largerio\ConcurrencyLimit\Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use Largerio\ConcurrencyLimit\ConcurrencyLimitServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use RuntimeException;

require __DIR__.'/../../../vendor/autoload.php';

$messages = fopen('php://fd/3', 'w');

if ($messages === false) {
    throw new RuntimeException('Start this worker through QueueWorkers: it reports on file descriptor 3.');
}

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

fwrite($messages, json_encode(['ready' => true], JSON_THROW_ON_ERROR)."\n");
fflush($messages);

exit($app->make(Kernel::class)->call('queue:work', [
    'connection' => $options['connection'],
    '--queue' => $options['queue'],
    '--sleep' => 0.1,
    '--max-time' => $options['max_time'],
]));
