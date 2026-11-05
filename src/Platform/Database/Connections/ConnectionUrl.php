<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Parses connection URLs used in .env (DB_CONN_<NAME>_URL):
 *
 *   postgres://user:pass@db2:5432/analytics?sslmode=require&schema=public
 *   mysql://user:pass@mariadb:3306/shop?charset=utf8mb4
 *   sqlite:///app/runtime/local.sqlite          (absolute path after the third slash)
 *   sqlsrv://sa:pass@mssql:1433/erp?encrypt=false
 *   oracle://scott:tiger@ora:1521/XEPDB1          (path = service name)
 *   mongodb://user:pass@mongo:27017/docs?authSource=admin&replicaSet=rs0
 *   mongodb+srv://user:pass@cluster0.example.net/docs
 *   clickhouse://default:pass@clickhouse:8123/events   (clickhouses:// = HTTPS)
 *
 * User name and password are URL-decoded, so special characters must be percent-encoded.
 * Query parameters become driver options; "readonly=1" marks the connection read-only.
 *
 * Author: ShokirjonMK
 */
final class ConnectionUrl
{
    public static function parse(string $name, #[SensitiveParameter] string $url, string $source = 'env', string $description = ''): ConnectionDefinition
    {
        $url = trim($url);
        if (!preg_match('~^([a-z][a-z0-9+]*)://~i', $url, $m)) {
            throw new InvalidArgumentException("Connection \"$name\": URL must start with <driver>://");
        }
        $scheme = strtolower($m[1]);
        $driver = DatabaseDriver::fromScheme($scheme);
        if ($driver === null) {
            throw new InvalidArgumentException("Connection \"$name\": unsupported scheme \"$scheme\". Supported: " . implode(', ', DatabaseDriver::all()));
        }

        if ($driver === DatabaseDriver::SQLITE) {
            $rest = substr($url, strlen('sqlite://'));
            [$path, $query] = array_pad(explode('?', $rest, 2), 2, '');
            parse_str($query, $q);
            $options = self::options($q) + ['path' => $path === '' ? ':memory:' : $path];
            return new ConnectionDefinition($name, $driver, database: $path, options: $options, readOnly: self::readOnly($q), source: $source, description: $description);
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            throw new InvalidArgumentException("Connection \"$name\": the URL has no host.");
        }
        parse_str($parts['query'] ?? '', $q);
        $options = self::options($q);
        if ($scheme === 'mongodb+srv') {
            $options['srv'] = true;
        }
        if ($scheme === 'clickhouses') {
            $options['tls'] = true;
        }

        return new ConnectionDefinition(
            $name,
            $driver,
            host: $parts['host'],
            port: (int) ($parts['port'] ?? 0),
            database: trim(rawurldecode($parts['path'] ?? ''), '/'),
            username: rawurldecode($parts['user'] ?? ''),
            password: rawurldecode($parts['pass'] ?? ''),
            options: $options,
            readOnly: self::readOnly($q),
            source: $source,
            description: $description,
        );
    }

    /** @param array<array-key, mixed> $q */
    private static function options(array $q): array
    {
        unset($q['readonly']);
        $out = [];
        foreach ($q as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $k) && is_scalar($v)) {
                $out[$k] = match (strtolower((string) $v)) {
                    'true' => true,
                    'false' => false,
                    default => (string) $v,
                };
            }
        }
        return $out;
    }

    /** @param array<array-key, mixed> $q */
    private static function readOnly(array $q): bool
    {
        return filter_var($q['readonly'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
