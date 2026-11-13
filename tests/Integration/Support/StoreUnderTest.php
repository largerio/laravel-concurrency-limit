<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Integration\Support;

use ErrorException;
use Illuminate\Database\QueryException;
use Memcached;
use PDO;
use Predis\Connection\Resource\Exception\StreamInitException;
use RedisException;
use RuntimeException;
use Throwable;

/**
 * A cache store of the integration suite: the configuration that points the
 * driver and its workers at it, a broken variant of that configuration, and
 * the exception its lock driver throws when the store is down, which the
 * package reports as the previous exception of StoreUnavailableException.
 */
final readonly class StoreUnderTest
{
    private const string PREFIX = 'concurrency-limit-integration';

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $broken
     * @param  class-string<Throwable>  $failure
     */
    private function __construct(
        public array $config,
        public array $broken,
        public string $failure,
        private ?string $sqlite = null,
        private ?string $notADirectory = null,
    ) {}

    public static function redis(string $client): self
    {
        $redis = fn (string $host, int $port): array => [
            'cache.prefix' => self::PREFIX,
            'concurrency-limit.store' => 'redis',
            'database.redis.client' => $client,
            'database.redis.options.prefix' => '',
            'database.redis.default' => ['host' => $host, 'port' => $port, 'database' => 0, 'max_retries' => 0, 'timeout' => 1],
            'database.redis.cache' => ['host' => $host, 'port' => $port, 'database' => 1, 'max_retries' => 0, 'timeout' => 1],
        ];

        return new self(
            config: $redis(self::env('REDIS_HOST', '127.0.0.1'), (int) self::env('REDIS_PORT', '6379')),
            broken: $redis('127.0.0.1', 1),
            failure: $client === 'predis' ? StreamInitException::class : RedisException::class,
        );
    }

    public static function memcached(bool $binaryProtocol = false): self
    {
        $memcached = fn (string $host, int $port): array => [
            'cache.prefix' => self::PREFIX,
            'concurrency-limit.store' => 'memcached',
            'cache.stores.memcached.options' => $binaryProtocol ? [Memcached::OPT_BINARY_PROTOCOL => true] : [],
            'cache.stores.memcached.servers' => [['host' => $host, 'port' => $port, 'weight' => 100]],
        ];

        return new self(
            config: $memcached(self::env('MEMCACHED_HOST', '127.0.0.1'), (int) self::env('MEMCACHED_PORT', '11211')),
            broken: $memcached('127.0.0.1', 1),
            failure: RuntimeException::class,
        );
    }

    /** A SQLite file in WAL mode, shared by every process, with a busy timeout. */
    public static function database(): self
    {
        $sqlite = sys_get_temp_dir().'/'.self::PREFIX.'.sqlite';

        $database = fn (string $lockTable): array => [
            'cache.prefix' => self::PREFIX,
            'concurrency-limit.store' => 'database',
            'database.connections.concurrency' => [
                'driver' => 'sqlite',
                'database' => $sqlite,
                'prefix' => '',
                'foreign_key_constraints' => false,
                'busy_timeout' => 10_000,
                'journal_mode' => 'wal',
                'synchronous' => 'normal',
            ],
            'cache.stores.database' => [
                'driver' => 'database',
                'connection' => 'concurrency',
                'table' => 'cache',
                'lock_connection' => 'concurrency',
                'lock_table' => $lockTable,
            ],
        ];

        return new self(
            config: $database('cache_locks'),
            broken: $database('missing_cache_locks'),
            failure: QueryException::class,
            sqlite: $sqlite,
        );
    }

    /** Broken by a lock directory that cannot exist: its parent is a file. */
    public static function file(): self
    {
        $directory = sys_get_temp_dir().'/'.self::PREFIX.'-file';
        $notADirectory = "{$directory}-not-a-directory";

        $file = fn (string $path): array => [
            'cache.prefix' => self::PREFIX,
            'concurrency-limit.store' => 'file',
            'cache.stores.file' => ['driver' => 'file', 'path' => $path, 'lock_path' => $path],
        ];

        return new self(
            config: $file($directory),
            broken: $file("{$notADirectory}/locks"),
            failure: ErrorException::class,
            notADirectory: $notADirectory,
        );
    }

    /** Creates what the store needs before any process uses it. */
    public function prepare(): void
    {
        if ($this->sqlite !== null) {
            $pdo = new PDO("sqlite:{$this->sqlite}");
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS cache_locks (key VARCHAR NOT NULL PRIMARY KEY, owner VARCHAR NOT NULL, expiration INTEGER NOT NULL)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS cache_locks_expiration_index ON cache_locks (expiration)');
        }

        if ($this->notADirectory !== null) {
            touch($this->notADirectory);
        }
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
