<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections;

/**
 * Catalog of supported database engines. One place that knows, per driver: the family (how it is queried), the default
 * port, the PHP extension and Composer package it needs, and the URL schemes accepted in DB_CONN_<NAME>_URL.
 *
 *  - sql:      PostgreSQL, MySQL/MariaDB, SQLite, SQL Server, Oracle — yiisoft/db connections (Query builder, Repository)
 *  - document: MongoDB — mongodb/mongodb (MongoDB\Database)
 *  - olap:     ClickHouse — HTTP interface (ClickHouseClient), no extension needed
 *
 * Author: ShokirjonMK
 */
final class DatabaseDriver
{
    public const PGSQL = 'pgsql';
    public const MYSQL = 'mysql';
    public const SQLITE = 'sqlite';
    public const SQLSRV = 'sqlsrv';
    public const OCI = 'oci';
    public const MONGODB = 'mongodb';
    public const CLICKHOUSE = 'clickhouse';

    /** @var array<string, array{label:string, family:string, port:int, extension:?string, class:string, schemes:list<string>}> */
    private const DRIVERS = [
        self::PGSQL => ['label' => 'PostgreSQL', 'family' => 'sql', 'port' => 5432, 'extension' => 'pdo_pgsql', 'class' => \Yiisoft\Db\Pgsql\Connection::class, 'schemes' => ['pgsql', 'postgres', 'postgresql']],
        self::MYSQL => ['label' => 'MySQL / MariaDB', 'family' => 'sql', 'port' => 3306, 'extension' => 'pdo_mysql', 'class' => \Yiisoft\Db\Mysql\Connection::class, 'schemes' => ['mysql', 'mariadb']],
        self::SQLITE => ['label' => 'SQLite', 'family' => 'sql', 'port' => 0, 'extension' => 'pdo_sqlite', 'class' => \Yiisoft\Db\Sqlite\Connection::class, 'schemes' => ['sqlite']],
        self::SQLSRV => ['label' => 'Microsoft SQL Server', 'family' => 'sql', 'port' => 1433, 'extension' => 'pdo_sqlsrv', 'class' => \Yiisoft\Db\Mssql\Connection::class, 'schemes' => ['sqlsrv', 'mssql']],
        self::OCI => ['label' => 'Oracle', 'family' => 'sql', 'port' => 1521, 'extension' => 'pdo_oci', 'class' => \Yiisoft\Db\Oracle\Connection::class, 'schemes' => ['oci', 'oracle']],
        self::MONGODB => ['label' => 'MongoDB', 'family' => 'document', 'port' => 27017, 'extension' => 'mongodb', 'class' => \MongoDB\Client::class, 'schemes' => ['mongodb', 'mongodb+srv']],
        self::CLICKHOUSE => ['label' => 'ClickHouse', 'family' => 'olap', 'port' => 8123, 'extension' => null, 'class' => ClickHouse\ClickHouseClient::class, 'schemes' => ['clickhouse', 'clickhouses']],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::DRIVERS);
    }

    public static function exists(string $driver): bool
    {
        return isset(self::DRIVERS[$driver]);
    }

    public static function label(string $driver): string
    {
        return self::DRIVERS[$driver]['label'] ?? $driver;
    }

    public static function family(string $driver): string
    {
        return self::DRIVERS[$driver]['family'] ?? 'sql';
    }

    public static function isSql(string $driver): bool
    {
        return self::family($driver) === 'sql';
    }

    public static function defaultPort(string $driver): int
    {
        return self::DRIVERS[$driver]['port'] ?? 0;
    }

    public static function extension(string $driver): ?string
    {
        return self::DRIVERS[$driver]['extension'] ?? null;
    }

    /** null when the driver can be used in this PHP runtime, otherwise the reason (shown in the panel). */
    public static function unavailableReason(string $driver): ?string
    {
        if (!self::exists($driver)) {
            return "Unknown driver \"$driver\".";
        }
        $ext = self::extension($driver);
        if ($ext !== null && !extension_loaded($ext)) {
            return "PHP extension \"$ext\" is not installed in this runtime.";
        }
        if (!class_exists(self::DRIVERS[$driver]['class'])) {
            return 'The Composer package for ' . self::label($driver) . ' is not installed.';
        }
        return null;
    }

    /** URL scheme (mysql, postgres, mongodb+srv, …) → driver. */
    public static function fromScheme(string $scheme): ?string
    {
        $scheme = strtolower($scheme);
        foreach (self::DRIVERS as $driver => $d) {
            if (in_array($scheme, $d['schemes'], true)) {
                return $driver;
            }
        }
        return null;
    }

    /** @return array<string, string> driver => label, for selects */
    public static function options(): array
    {
        return array_map(static fn(array $d) => $d['label'], self::DRIVERS);
    }
}
