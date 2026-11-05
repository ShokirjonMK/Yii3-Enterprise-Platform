<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections;

use App\Shared\Infrastructure\Database\ConnectionFactory;
use InvalidArgumentException;
use PDO;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Builds a yiisoft/db connection for any SQL driver of a named connection. The result is the same
 * ConnectionInterface the platform uses, so Query builder, Command, transactions and Repository code work unchanged.
 *
 * Read-only connections are enforced by the server where the engine supports it (PostgreSQL
 * default_transaction_read_only, MySQL/MariaDB SESSION TRANSACTION READ ONLY, SQLite query_only); for SQL Server and
 * Oracle use a read-only database login.
 *
 * Author: ShokirjonMK
 */
final class SqlConnectionFactory
{
    public function __construct(private readonly SchemaCache $schemaCache) {}

    public function create(ConnectionDefinition $d): ConnectionInterface
    {
        $timeout = max(1, (int) $d->option('timeout', 5));
        $attributes = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $timeout,
        ];

        switch ($d->driver) {
            case DatabaseDriver::PGSQL:
                return ConnectionFactory::create([
                    'host' => $d->host, 'port' => (string) $d->port(), 'name' => $d->database, 'user' => $d->username,
                    'password' => $d->password, 'sslmode' => (string) $d->option('sslmode', 'prefer'),
                    'statementTimeoutMs' => (int) $d->option('statementTimeoutMs', 30000), 'readOnly' => $d->readOnly,
                ], $this->schemaCache, 'yii3-platform:' . $d->name);

            case DatabaseDriver::MYSQL:
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d->host, $d->port(), $d->database, $d->option('charset', 'utf8mb4'));
                $init = ["SET time_zone = '+00:00'"];
                if ($d->readOnly) {
                    $init[] = 'SET SESSION TRANSACTION READ ONLY';
                }
                $attributes[PDO::MYSQL_ATTR_INIT_COMMAND] = implode('; ', $init);
                if ($d->option('tls')) {
                    $attributes[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) $d->option('verify', true);
                    $attributes[PDO::MYSQL_ATTR_SSL_CA] = (string) $d->option('ca', '/etc/ssl/certs/ca-certificates.crt');
                }
                $driver = new \Yiisoft\Db\Mysql\Driver($dsn, $d->username, $d->password);
                $driver->attributes($attributes);
                return new \Yiisoft\Db\Mysql\Connection($driver, $this->schemaCache);

            case DatabaseDriver::SQLITE:
                $path = (string) ($d->option('path') ?: $d->database);
                if ($path === '') {
                    throw new InvalidArgumentException('SQLite connection needs a file path.');
                }
                if ($path !== ':memory:' && !is_dir(dirname($path)) && !@mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
                    throw new InvalidArgumentException('Cannot create the SQLite directory ' . dirname($path) . '.');
                }
                $driver = new \Yiisoft\Db\Sqlite\Driver('sqlite:' . $path);
                $driver->attributes($attributes);
                $conn = new \Yiisoft\Db\Sqlite\Connection($driver, $this->schemaCache);
                if ($d->readOnly) {
                    $conn->open();
                    $conn->createCommand('PRAGMA query_only = ON')->execute();
                }
                return $conn;

            case DatabaseDriver::SQLSRV:
                $dsn = sprintf(
                    'sqlsrv:Server=%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=%d',
                    $d->host, $d->port(), $d->database,
                    $d->option('encrypt', true) ? 'yes' : 'no', $d->option('trustServerCertificate', false) ? 'yes' : 'no', $timeout,
                );
                if ($d->readOnly) {
                    $dsn .= ';ApplicationIntent=ReadOnly';
                }
                unset($attributes[PDO::ATTR_TIMEOUT]); // not supported by pdo_sqlsrv (LoginTimeout is in the DSN)
                $driver = new \Yiisoft\Db\Mssql\Driver($dsn, $d->username, $d->password);
                $driver->attributes($attributes);
                return new \Yiisoft\Db\Mssql\Connection($driver, $this->schemaCache);

            case DatabaseDriver::OCI:
                $service = (string) ($d->option('service') ?: $d->database);
                $dsn = sprintf('oci:dbname=//%s:%d/%s;charset=%s', $d->host, $d->port(), $service, $d->option('charset', 'AL32UTF8'));
                unset($attributes[PDO::ATTR_TIMEOUT]);
                $driver = new \Yiisoft\Db\Oracle\Driver($dsn, $d->username, $d->password);
                $driver->attributes($attributes);
                return new \Yiisoft\Db\Oracle\Connection($driver, $this->schemaCache);
        }

        throw new InvalidArgumentException("\"{$d->driver}\" is not an SQL driver.");
    }
}
