<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database;

use PDO;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;
use Yiisoft\Db\Pgsql\Dsn;

/**
 * Builds yiisoft/db-pgsql connections. Statement timeout and application name are set via the
 * libpq "options" DSN parameter so they apply to every pooled/new connection.
 *
 * Web requests (PHP-FPM) use persistent connections: opening a PostgreSQL connection with SCRAM
 * authentication costs ~5 ms, i.e. a third of a typical request. Each FPM worker keeps one connection
 * (pm.max_children per pod — size max_connections / PgBouncer accordingly). Safe because the web tier holds
 * no session state: PDO rolls back an open transaction when the request ends, dead connections are
 * detected and re-opened, and session-level advisory locks are used only by CLI processes (scheduler),
 * which keep regular connections. Disable with DB_PERSISTENT=false.
 *
 * Author: ShokirjonMK
 */
final class ConnectionFactory
{
    /**
     * @param array{host:string,port:string,name:string,user:string,password:string,sslmode?:string,statementTimeoutMs?:int} $config
     */
    public static function create(array $config, SchemaCache $schemaCache, string $applicationName = 'yii3-platform'): Connection
    {
        $options = [
            'sslmode' => $config['sslmode'] ?? 'disable',
            'application_name' => $applicationName,
            'options' => sprintf("'-c statement_timeout=%d -c lock_timeout=10000 -c idle_in_transaction_session_timeout=60000'", $config['statementTimeoutMs'] ?? 30000),
            'connect_timeout' => '5',
        ];

        $driver = new Driver(
            (string) new Dsn('pgsql', $config['host'], $config['name'], (string) $config['port'], $options),
            $config['user'],
            $config['password'],
        );
        $driver->charset('UTF8');
        $driver->attributes([
            PDO::ATTR_PERSISTENT => self::persistent(),
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        return new Connection($driver, $schemaCache);
    }

    private static function persistent(): bool
    {
        return PHP_SAPI === 'fpm-fcgi' && filter_var(getenv('DB_PERSISTENT') ?: 'true', FILTER_VALIDATE_BOOLEAN);
    }
}
