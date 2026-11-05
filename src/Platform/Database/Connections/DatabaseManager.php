<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections;

use App\Platform\Database\Connections\ClickHouse\ClickHouseClient;
use App\Shared\Domain\Exception\ConflictException;
use MongoDB\Client as MongoClient;
use MongoDB\Database as MongoDatabase;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Entry point for working with several databases at once (any mix of PostgreSQL, MySQL/MariaDB, SQLite, SQL Server,
 * Oracle, MongoDB and ClickHouse):
 *
 *   $dbs->sql('analytics')->createCommand('SELECT …')->queryAll();       // yiisoft/db ConnectionInterface
 *   new Query($dbs->sql('archive'))->from('orders')->where([...])->all(); // Query builder / Repository code as usual
 *   $dbs->mongo('docs')->selectCollection('files')->find([...]);          // MongoDB\Database
 *   $dbs->clickhouse('events')->insert('page_views', $rows);               // ClickHouseClient
 *
 * "platform" is the platform database itself (the primary ConnectionInterface). Connections are opened lazily on first
 * use and reused for the rest of the request / worker loop; disabled connections refuse to open.
 *
 * Author: ShokirjonMK
 */
final class DatabaseManager
{
    /** @var array<string, array{key:string, client:object}> */
    private array $open = [];

    public function __construct(
        private readonly ConnectionStore $store,
        private readonly ConnectionInterface $platform,
        private readonly SqlConnectionFactory $sqlFactory,
        private readonly MongoClientFactory $mongoFactory,
    ) {}

    /** @return array<string, ConnectionDefinition> */
    public function definitions(): array
    {
        return $this->store->all();
    }

    public function definition(string $name): ConnectionDefinition
    {
        return $this->store->find($name);
    }

    public function has(string $name): bool
    {
        return $this->store->exists($name);
    }

    public function sql(string $name): ConnectionInterface
    {
        if ($name === ConnectionStore::PLATFORM) {
            return $this->platform;
        }
        $d = $this->usable($name, 'sql');
        /** @var ConnectionInterface */
        return $this->remember($d, fn() => $this->sqlFactory->create($d));
    }

    public function mongoClient(string $name): MongoClient
    {
        $d = $this->usable($name, 'document');
        /** @var MongoClient */
        return $this->remember($d, fn() => $this->mongoFactory->create($d));
    }

    /** The connection's default database (the path of the URL / "Database" field). */
    public function mongo(string $name): MongoDatabase
    {
        return $this->mongoClient($name)->selectDatabase($this->definition($name)->database);
    }

    public function clickhouse(string $name): ClickHouseClient
    {
        $d = $this->usable($name, 'olap');
        /** @var ClickHouseClient */
        return $this->remember($d, fn() => $this->clickhouseClient($d));
    }

    /** Closes cached clients (after the definition was edited, or between worker jobs). */
    public function forget(?string $name = null): void
    {
        foreach ($name === null ? array_keys($this->open) : [$name] as $n) {
            $client = $this->open[$n]['client'] ?? null;
            if ($client instanceof ConnectionInterface) {
                $client->close();
            }
            unset($this->open[$n]);
        }
    }

    /**
     * Opens a fresh connection (never cached) and runs a trivial query.
     *
     * @return array{ok:bool, latency_ms:int, version:?string, error:?string}
     */
    public function test(ConnectionDefinition $d): array
    {
        $reason = DatabaseDriver::unavailableReason($d->driver);
        if ($reason !== null) {
            return ['ok' => false, 'latency_ms' => 0, 'version' => null, 'error' => $reason];
        }
        $t = hrtime(true);
        try {
            $version = match ($d->family()) {
                'sql' => $this->sqlVersion($d->name === ConnectionStore::PLATFORM ? $this->platform : $c = $this->sqlFactory->create($d), $d->driver),
                'document' => $this->mongoVersion($this->mongoFactory->create($d)),
                'olap' => $this->clickhouseClient($d)->version(),
            };
            if (isset($c)) {
                $c->close();
            }
            return ['ok' => true, 'latency_ms' => (int) ((hrtime(true) - $t) / 1e6), 'version' => $version, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'latency_ms' => (int) ((hrtime(true) - $t) / 1e6), 'version' => null, 'error' => self::safeError($e, $d)];
        }
    }

    /** Error text with the connection password removed (drivers sometimes echo connection strings). */
    public static function safeError(Throwable $e, ConnectionDefinition $d): string
    {
        $msg = mb_substr(trim($e->getMessage()), 0, 600);
        return $d->password !== '' ? str_replace($d->password, '***', $msg) : $msg;
    }

    public function sqlVersion(ConnectionInterface $c, string $driver): string
    {
        $sql = match ($driver) {
            DatabaseDriver::PGSQL => 'SHOW server_version',
            DatabaseDriver::MYSQL => 'SELECT VERSION()',
            DatabaseDriver::SQLITE => 'SELECT sqlite_version()',
            DatabaseDriver::SQLSRV => "SELECT CAST(SERVERPROPERTY('ProductVersion') AS varchar(64))",
            DatabaseDriver::OCI => "SELECT version FROM product_component_version WHERE ROWNUM = 1",
            default => 'SELECT 1',
        };
        return (string) $c->createCommand($sql)->queryScalar();
    }

    public function mongoVersion(MongoClient $client): string
    {
        $info = $client->selectDatabase('admin')->command(['buildInfo' => 1])->toArray()[0] ?? [];
        return (string) ($info['version'] ?? '');
    }

    private function clickhouseClient(ConnectionDefinition $d): ClickHouseClient
    {
        $base = ($d->option('tls') ? 'https://' : 'http://') . $d->host . ':' . $d->port();
        $settings = $d->readOnly ? ['readonly' => 2] : [];
        return new ClickHouseClient($base, $d->database ?: 'default', $d->username ?: 'default', $d->password, (float) $d->option('timeout', 30), $settings);
    }

    private function usable(string $name, string $family): ConnectionDefinition
    {
        $d = $this->store->find($name);
        if (!$d->enabled) {
            throw new ConflictException("Database connection \"$name\" is disabled.", 'connection_disabled');
        }
        if ($d->family() !== $family) {
            throw new ConflictException("Database connection \"$name\" is " . DatabaseDriver::label($d->driver) . ', not a ' . ['sql' => 'SQL', 'document' => 'MongoDB', 'olap' => 'ClickHouse'][$family] . ' connection.', 'connection_type');
        }
        $reason = DatabaseDriver::unavailableReason($d->driver);
        if ($reason !== null) {
            throw new ConflictException($reason, 'driver_unavailable');
        }
        return $d;
    }

    private function remember(ConnectionDefinition $d, callable $make): object
    {
        $key = $d->source . ':' . $d->version . ':' . ($d->updatedAt ?? '');
        if (($this->open[$d->name]['key'] ?? null) !== $key) {
            $this->forget($d->name);
            $this->open[$d->name] = ['key' => $key, 'client' => $make()];
        }
        return $this->open[$d->name]['client'];
    }
}
