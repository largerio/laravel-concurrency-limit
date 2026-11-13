<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs\OverlapProbeJob;
use Largerio\ConcurrencyLimit\Tests\Integration\Support\QueueWorkers;
use Largerio\ConcurrencyLimit\Tests\Integration\Support\StoreUnderTest;

/**
 * The lines the jobs appended to the log, as soon as there are `$count` of
 * them or once `$timeout` seconds have passed.
 *
 * @return list<array{number: int, start: float, end: float, attempts: int, pid: int}>
 */
function overlapProbeRuns(string $log, int $count, float $timeout): array
{
    $deadline = microtime(true) + $timeout;
    $lines = [];

    while (true) {
        $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        if (count($lines) >= $count || microtime(true) >= $deadline) {
            break;
        }

        usleep(100_000);
    }

    return array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
}

it('never runs more jobs at once than the limit, across queue workers', function (): void {
    $store = StoreUnderTest::redis('phpredis');
    $store->prepare();

    $run = bin2hex(random_bytes(6));
    $queue = "integration-jobs-{$run}";
    $log = sys_get_temp_dir()."/concurrency-limit-jobs-{$run}.log";
    $failedJobs = sys_get_temp_dir()."/concurrency-limit-jobs-{$run}.sqlite";

    $pdo = new PDO("sqlite:{$failedJobs}");
    $pdo->exec('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid VARCHAR NOT NULL UNIQUE, connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');

    $config = $store->config + [
        'database.connections.failed_jobs' => ['driver' => 'sqlite', 'database' => $failedJobs, 'prefix' => '', 'foreign_key_constraints' => false],
        'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => 'failed_jobs',
        'queue.failed.table' => 'failed_jobs',
    ];

    config($config);

    $workers = new QueueWorkers(4, ['config' => $config, 'connection' => 'redis', 'queue' => $queue, 'max_time' => 120]);

    try {
        foreach (range(1, 20) as $number) {
            Queue::connection('redis')->pushOn($queue, new OverlapProbeJob($number, $run, $log, workMs: 200));
        }

        overlapProbeRuns($log, 20, timeout: 90);

        // Long enough for a job handled twice to show up.
        usleep(500_000);

        $workers->stop();
        $runs = overlapProbeRuns($log, 20, timeout: 0);
        $handled = array_count_values(array_column($runs, 'number'));
        ksort($handled);

        expect($handled)->toBe(array_fill_keys(range(1, 20), 1))
            ->and(QueueWorkers::maxOverlap($runs))->toBeLessThanOrEqual(2)
            ->and(max(array_column($runs, 'attempts')))->toBeGreaterThan(1)
            ->and((int) $pdo->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn())->toBe(0)
            ->and(Queue::connection('redis')->size($queue))->toBe(0);
    } finally {
        $workers->kill();
        Queue::connection('redis')->clear($queue);

        foreach ([$log, $failedJobs] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});
