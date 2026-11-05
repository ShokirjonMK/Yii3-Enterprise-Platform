<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections;

use SensitiveParameter;

/**
 * One named database connection, whatever the engine.
 *
 * source: "system" — the platform database itself (DB_* variables, read-only in the panel),
 *         "env"    — declared in .env (DB_CONNECTIONS + DB_CONN_<NAME>_URL), read-only in the panel,
 *         "panel"  — created by a superadmin in Admin → Databases (password encrypted at rest).
 *
 * options (driver specific, all optional): sslmode, charset, schema, timeout (seconds), tls (bool), authSource,
 * replicaSet, srv (bool, mongodb+srv), path (SQLite file), service (Oracle service name), encrypt (SQL Server).
 *
 * Author: ShokirjonMK
 */
final class ConnectionDefinition
{
    /** @param array<string, string|int|bool> $options */
    public function __construct(
        public readonly string $name,
        public readonly string $driver,
        public readonly string $host = '',
        public readonly int $port = 0,
        public readonly string $database = '',
        public readonly string $username = '',
        #[SensitiveParameter] public readonly string $password = '',
        public readonly array $options = [],
        public readonly bool $readOnly = false,
        public readonly bool $enabled = true,
        public readonly string $source = 'panel',
        public readonly string $description = '',
        public readonly int $version = 1,
        public readonly ?string $updatedAt = null,
    ) {}

    public function family(): string
    {
        return DatabaseDriver::family($this->driver);
    }

    public function port(): int
    {
        return $this->port > 0 ? $this->port : DatabaseDriver::defaultPort($this->driver);
    }

    public function option(string $key, string|int|bool|null $default = null): string|int|bool|null
    {
        return $this->options[$key] ?? $default;
    }

    public function editable(): bool
    {
        return $this->source === 'panel';
    }

    /** Short, password-free description for lists and logs, e.g. "pgsql://app@db2:5432/analytics". */
    public function target(): string
    {
        if ($this->driver === DatabaseDriver::SQLITE) {
            return 'sqlite://' . ($this->option('path') ?: $this->database);
        }
        $scheme = $this->driver === DatabaseDriver::MONGODB && $this->option('srv') ? 'mongodb+srv' : $this->driver;
        $user = $this->username !== '' ? $this->username . '@' : '';
        $port = $this->driver === DatabaseDriver::MONGODB && $this->option('srv') ? '' : ':' . $this->port();
        return $scheme . '://' . $user . $this->host . $port . ($this->database !== '' ? '/' . $this->database : '');
    }

    /** @return array<string, mixed> without the password (for audit / views) */
    public function toPublicArray(): array
    {
        return [
            'name' => $this->name, 'driver' => $this->driver, 'host' => $this->host, 'port' => $this->port(),
            'database' => $this->database, 'username' => $this->username, 'options' => $this->options,
            'read_only' => $this->readOnly, 'enabled' => $this->enabled, 'source' => $this->source,
            'description' => $this->description, 'has_password' => $this->password !== '',
        ];
    }
}
