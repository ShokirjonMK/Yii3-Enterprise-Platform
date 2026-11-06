<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections;

use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Security\Encrypter;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Where named connections come from:
 *  - "platform": the platform database itself (always present),
 *  - .env:       DB_CONNECTIONS=analytics,docs + DB_CONN_ANALYTICS_URL=postgres://… (params platform.databases),
 *  - panel:      table database_connections, managed by a superadmin; the password is encrypted (Encrypter, purpose
 *                "database-connection") and never shown again.
 * Names are unique across all sources; .env wins over the panel and cannot be edited in the panel.
 *
 * Author: ShokirjonMK
 */
final class ConnectionStore
{
    public const PLATFORM = 'platform';
    private const PURPOSE = 'database-connection';

    /** @var array<string, ConnectionDefinition>|null */
    private ?array $cache = null;
    private float $cachedAt = 0.0;

    /**
     * @param array{host:string,port:string,name:string,user:string,password:string,sslmode?:string} $platformDb
     * @param list<array{name:string,url:string,description?:string}> $envConnections
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Encrypter $encrypter,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly array $platformDb,
        private readonly array $envConnections = [],
        private readonly string $sqliteDirectory = '',
    ) {}

    /** @return array<string, ConnectionDefinition> platform first, then .env, then panel (alphabetical) */
    public function all(bool $refresh = false): array
    {
        // long-running workers see panel changes within 30 s; web requests load once per request
        if (!$refresh && $this->cache !== null && microtime(true) - $this->cachedAt < 30) {
            return $this->cache;
        }
        $out = [self::PLATFORM => new ConnectionDefinition(
            self::PLATFORM,
            DatabaseDriver::PGSQL,
            $this->platformDb['host'],
            (int) $this->platformDb['port'],
            $this->platformDb['name'],
            $this->platformDb['user'],
            $this->platformDb['password'],
            ['sslmode' => $this->platformDb['sslmode'] ?? 'disable'],
            source: 'system',
            description: 'Platform database (users, audit, settings, queue…)',
        )];
        foreach ($this->envConnections as $c) {
            try {
                $out[$c['name']] = ConnectionUrl::parse($c['name'], $c['url'], 'env', $c['description'] ?? '');
            } catch (Throwable $e) {
                $this->logger->error('Invalid database connection in .env', ['name' => $c['name'], 'error' => $e->getMessage()]);
            }
        }
        try {
            $rows = (new Query($this->db))->from('database_connections')->orderBy(['name' => SORT_ASC])->all();
        } catch (Throwable) {
            $rows = []; // table not migrated yet
        }
        foreach ($rows as $r) {
            if (!isset($out[$r['name']])) {
                $out[$r['name']] = $this->fromRow($r);
            }
        }
        $this->cachedAt = microtime(true);
        return $this->cache = $out;
    }

    public function find(string $name): ConnectionDefinition
    {
        return $this->all()[$name] ?? throw new NotFoundException("Database connection \"$name\" not found.");
    }

    public function exists(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /** @param array<string, mixed> $in */
    public function create(string $actorId, array $in): ConnectionDefinition
    {
        $data = $this->validate($in, null);
        if ($this->exists($data['name'])) {
            throw ValidationException::single('name', 'A connection with this name already exists.');
        }
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $this->db->transaction(function () use ($data, $actorId, $now, $in): void {
            $this->db->createCommand()->insert('database_connections', $data + [
                'password_enc' => ($p = (string) ($in['password'] ?? '')) === '' ? null : $this->encrypter->encrypt($p, self::PURPOSE),
                'created_by' => $actorId, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now, 'version' => 1,
            ])->execute();
            $this->audit->record('database.connection.created', 'database_connection', $data['name'], null, self::auditable($data), category: 'config', severity: 'warning', actorId: $actorId);
        });
        return $this->all(true)[$data['name']];
    }

    /** @param array<string, mixed> $in password: '' keeps the stored one; clear_password=1 removes it */
    public function update(string $actorId, string $name, array $in, ?int $expectedVersion = null): ConnectionDefinition
    {
        $current = $this->editable($name);
        $data = $this->validate(['name' => $name] + $in, $current);
        unset($data['name']);
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $set = $data + ['updated_by' => $actorId, 'updated_at' => $now];
        $password = (string) ($in['password'] ?? '');
        if ($password !== '') {
            $set['password_enc'] = $this->encrypter->encrypt($password, self::PURPOSE);
        } elseif (($in['clear_password'] ?? '') === '1') {
            $set['password_enc'] = null;
        }
        $this->db->transaction(function () use ($name, $set, $expectedVersion, $current, $actorId, $password, $in): void {
            $where = ['name' => $name] + ($expectedVersion !== null ? ['version' => $expectedVersion] : []);
            $n = $this->db->createCommand()->update('database_connections', $set + ['version' => new \Yiisoft\Db\Expression\Expression('version + 1')], $where)->execute();
            if ($n === 0) {
                throw new ConflictException('The connection was changed by someone else. Reload and try again.', 'stale_version');
            }
            $after = self::auditable($set) + ['password_changed' => $password !== '' || ($in['clear_password'] ?? '') === '1'];
            $this->audit->record('database.connection.updated', 'database_connection', $name, $current->toPublicArray(), $after, category: 'config', severity: 'warning', actorId: $actorId);
        });
        return $this->all(true)[$name];
    }

    public function setEnabled(string $actorId, string $name, bool $enabled): void
    {
        $current = $this->editable($name);
        $this->db->createCommand()->update('database_connections', ['enabled' => $enabled, 'updated_by' => $actorId, 'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM), 'version' => new \Yiisoft\Db\Expression\Expression('version + 1')], ['name' => $name])->execute();
        $this->audit->record($enabled ? 'database.connection.enabled' : 'database.connection.disabled', 'database_connection', $name, ['enabled' => $current->enabled], ['enabled' => $enabled], category: 'config', severity: 'warning', actorId: $actorId);
        $this->cache = null;
    }

    public function delete(string $actorId, string $name): void
    {
        $current = $this->editable($name);
        $this->db->transaction(function () use ($name, $current, $actorId): void {
            $this->db->createCommand()->delete('database_connections', ['name' => $name])->execute();
            $this->audit->record('database.connection.deleted', 'database_connection', $name, $current->toPublicArray(), null, category: 'config', severity: 'warning', actorId: $actorId);
        });
        $this->cache = null;
    }

    /** Builds a definition from unsaved form input (for "Test connection" before saving). */
    public function draft(array $in, ?string $existing = null): ConnectionDefinition
    {
        $current = $existing !== null && $this->exists($existing) ? $this->find($existing) : null;
        $data = $this->validate(['name' => $existing ?? (string) ($in['name'] ?? 'draft')] + $in, $current, false);
        $password = (string) ($in['password'] ?? '');
        if ($password === '' && $current !== null && ($in['clear_password'] ?? '') !== '1') {
            $password = $current->password;
        }
        return new ConnectionDefinition(
            $data['name'],
            $data['driver'],
            $data['host'],
            (int) $data['port'],
            $data['database'],
            $data['username'],
            $password,
            json_decode($data['options'], true) ?: [],
            $data['read_only'],
            true,
            'panel',
        );
    }

    private function editable(string $name): ConnectionDefinition
    {
        $d = $this->find($name);
        if (!$d->editable()) {
            throw new ConflictException('This connection is defined in .env (or is the platform database) and cannot be changed in the panel.', 'connection_readonly');
        }
        return $d;
    }

    /** @return array{name:string,driver:string,host:string,port:int,database:string,username:string,options:string,read_only:bool,enabled:bool,description:string} */
    private function validate(array $in, ?ConnectionDefinition $current, bool $checkName = true): array
    {
        $errors = [];
        $name = strtolower(trim((string) ($in['name'] ?? '')));
        if ($checkName && $current === null && !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name)) {
            $errors['name'][] = 'Use 2–64 characters: lower-case letters, digits and "_", starting with a letter.';
        }
        $driver = (string) ($in['driver'] ?? $current?->driver ?? '');
        if (!DatabaseDriver::exists($driver)) {
            $errors['driver'][] = 'Choose a supported database type.';
        }
        $host = trim((string) ($in['host'] ?? ''));
        $port = (int) ($in['port'] ?? 0);
        $database = trim((string) ($in['database'] ?? ''));
        $options = [];
        $rawOptions = trim((string) ($in['options'] ?? ''));
        if ($rawOptions !== '') {
            $decoded = json_decode($rawOptions, true);
            if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                $errors['options'][] = 'Options must be a JSON object, e.g. {"sslmode": "require"}.';
            } else {
                foreach ($decoded as $k => $v) {
                    if (!is_string($k) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $k) || !is_scalar($v)) {
                        $errors['options'][] = "Option \"$k\" is invalid (names: letters/digits/_; values: text, number or true/false).";
                        break;
                    }
                    $options[$k] = $v;
                }
            }
        }
        if ($driver === DatabaseDriver::SQLITE) {
            $path = $database;
            if ($path === '' || str_contains($path, '..') || !preg_match('/^[A-Za-z0-9_\-\/.]+\.(sqlite|sqlite3|db)$/', $path)) {
                $errors['database'][] = 'SQLite: a file name such as "local.sqlite" (stored in runtime/databases).';
            } else {
                $options['path'] = rtrim($this->sqliteDirectory, '/') . '/' . ltrim(basename($path), '/');
            }
        } elseif ($driver !== '') {
            if ($host === '' || !preg_match('/^[A-Za-z0-9.\-_:\[\]]{1,255}$/', $host)) {
                $errors['host'][] = 'Enter a host name or IP address.';
            }
            if ($port < 0 || $port > 65535) {
                $errors['port'][] = 'Port must be between 1 and 65535 (0 = default).';
            }
            if ($database === '' && $driver !== DatabaseDriver::CLICKHOUSE) {
                $errors['database'][] = $driver === DatabaseDriver::OCI ? 'Enter the Oracle service name.' : 'Enter the database name.';
            }
        }
        $description = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            $errors['description'][] = 'At most 500 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'name' => $current?->name ?? $name,
            'driver' => $driver,
            'host' => $driver === DatabaseDriver::SQLITE ? '' : $host,
            'port' => $port,
            'database' => $database === '' && $driver === DatabaseDriver::CLICKHOUSE ? 'default' : $database,
            'username' => trim((string) ($in['username'] ?? '')),
            'options' => json_encode((object) $options, JSON_THROW_ON_ERROR),
            'read_only' => filter_var($in['read_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'enabled' => array_key_exists('enabled', $in) ? filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN) : ($current?->enabled ?? true),
            'description' => $description,
        ];
    }

    private function fromRow(array $r): ConnectionDefinition
    {
        $password = '';
        if (($r['password_enc'] ?? null) !== null && $r['password_enc'] !== '') {
            try {
                $password = $this->encrypter->decrypt((string) $r['password_enc'], self::PURPOSE);
            } catch (Throwable) {
                $this->logger->error('Database connection password cannot be decrypted (APP_SECRET changed?)', ['name' => $r['name']]);
            }
        }
        $options = is_string($r['options']) ? (json_decode($r['options'], true) ?: []) : (array) $r['options'];
        return new ConnectionDefinition(
            (string) $r['name'],
            (string) $r['driver'],
            (string) $r['host'],
            (int) $r['port'],
            (string) $r['database'],
            (string) $r['username'],
            $password,
            $options,
            self::bool($r['read_only']),
            self::bool($r['enabled']),
            'panel',
            (string) $r['description'],
            (int) $r['version'],
            (string) $r['updated_at'],
        );
    }

    private static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }

    private static function auditable(array $data): array
    {
        unset($data['password_enc']);
        return $data;
    }
}
