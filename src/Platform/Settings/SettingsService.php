<?php

declare(strict_types=1);

namespace App\Platform\Settings;

use App\Environment;
use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Security\Encrypter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Typed, scoped, versioned settings with rollback. Values are cached per process and keyed by the
 * cluster-wide {@see ConfigRevision}, so a change propagates to all instances within seconds.
 */
final class SettingsService
{
    private const NIL = '00000000-0000-0000-0000-000000000000';

    /** @var array<string, array<string, mixed>>|null scopeKey => [key => value] */
    private ?array $cache = null;
    private int $cacheRevision = -1;

    /** @param array<string, array<string,mixed>> $definitions */
    public function __construct(
        private readonly array $definitions,
        private readonly ConnectionInterface $db,
        private readonly ConfigRevision $revision,
        private readonly Encrypter $encrypter,
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
    ) {}

    /** @return array<string, array<string,mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function definition(string $key): array
    {
        return $this->definitions[$key] ?? throw new NotFoundException("Unknown setting \"$key\".");
    }

    public function get(string $key, ?string $tenantId = null, ?string $userId = null): mixed
    {
        $def = $this->definition($key);
        if (isset($def['env'])) {
            $env = Environment::nullableString($def['env']);
            if ($env !== null) {
                return $this->cast($def, $env);
            }
        }
        $values = $this->load();
        foreach ([['user', $userId], ['tenant', $tenantId], ['global', null]] as [$scope, $id]) {
            if ($scope !== 'global' && $id === null) {
                continue;
            }
            $sk = $scope . ':' . ($id ?? '');
            if (array_key_exists($key, $values[$sk] ?? [])) {
                $v = $values[$sk][$key];
                return $def['type'] === 'secret' && is_string($v) ? $this->encrypter->decrypt($v, 'settings') : $v;
            }
        }
        return $def['default'] ?? null;
    }

    /** Where the effective value comes from: env | user | tenant | global | default. */
    public function source(string $key, ?string $tenantId = null): string
    {
        $def = $this->definition($key);
        if (isset($def['env']) && Environment::nullableString($def['env']) !== null) {
            return 'env';
        }
        $values = $this->load();
        if ($tenantId !== null && array_key_exists($key, $values['tenant:' . $tenantId] ?? [])) {
            return 'tenant';
        }
        return array_key_exists($key, $values['global:'] ?? []) ? 'global' : 'default';
    }

    /**
     * @param string $permission permission required to change the key (sections that own a settings group,
     *                           e.g. notifications.manage for channel credentials, pass their own permission)
     */
    public function set(string $actorId, string $key, mixed $value, string $scope = 'global', ?string $scopeId = null, string $comment = '', string $permission = 'settings.manage'): void
    {
        $def = $this->definition($key);
        $this->access->assert($actorId, $permission);
        if (($def['critical'] ?? false) && !$this->access->isSuperadmin($actorId)) {
            throw new AccessDeniedException('Only a superadmin can change this critical setting.');
        }
        $scopes = $def['scopes'] ?? ['global'];
        if (!in_array($scope, $scopes, true)) {
            throw ValidationException::single('scope', "Setting \"$key\" cannot be set at scope \"$scope\".");
        }
        if (($scope === 'global') !== ($scopeId === null)) {
            throw ValidationException::single('scope_id', 'Scope id is required for tenant/user scope.');
        }
        $typed = $this->validate($key, $def, $value);
        $stored = $def['type'] === 'secret' ? ($typed === null || $typed === '' ? null : $this->encrypter->encrypt((string) $typed, 'settings')) : $typed;
        $before = $this->rawValue($key, $scope, $scopeId);

        $this->db->transaction(function () use ($key, $scope, $scopeId, $stored, $actorId, $comment, $def, $before, $typed): void {
            $json = json_encode($stored, JSON_UNESCAPED_UNICODE);
            // Version numbers are monotonic per (key, scope) across resets, so history/rollback stay unambiguous
            $version = 1 + (int) $this->db->createCommand(
                'SELECT COALESCE(max(version), 0) FROM setting_versions WHERE key = :k AND scope = :s AND scope_id IS NOT DISTINCT FROM CAST(:sid AS uuid)',
                [':k' => $key, ':s' => $scope, ':sid' => $scopeId],
            )->queryScalar();
            $row = $this->db->createCommand(
                "INSERT INTO settings (key, scope, scope_id, value, is_secret, version, updated_by)
                 VALUES (:k, :s, :sid, CAST(:v AS jsonb), :secret, :ver, :u)
                 ON CONFLICT (key, scope, COALESCE(scope_id, '" . self::NIL . "'::uuid))
                 DO UPDATE SET value = EXCLUDED.value, version = EXCLUDED.version, updated_by = EXCLUDED.updated_by, updated_at = now()
                 RETURNING version",
                [':k' => $key, ':s' => $scope, ':sid' => $scopeId, ':v' => $json, ':secret' => $def['type'] === 'secret', ':ver' => $version, ':u' => $actorId],
            )->queryOne();
            $this->db->createCommand()->insert('setting_versions', [
                'key' => $key, 'scope' => $scope, 'scope_id' => $scopeId, 'value' => $json,
                'version' => (int) $row['version'], 'changed_by' => $actorId, 'comment' => mb_substr($comment, 0, 500),
            ])->execute();
            $mask = static fn($v) => $def['type'] === 'secret' ? ($v === null ? null : '***') : $v;
            $this->audit->record(
                'settings.changed',
                'setting',
                $key,
                ['value' => $mask($before)],
                ['value' => $mask($typed)],
                ['scope' => $scope, 'scope_id' => $scopeId, 'version' => (int) $row['version'], 'comment' => $comment],
                category: 'config',
                severity: ($def['critical'] ?? false) ? 'warning' : 'notice',
            );
        });
        $this->revision->bump();
        $this->cache = null;
    }

    /** Removes an override (falls back to the next scope / default). */
    public function reset(string $actorId, string $key, string $scope = 'global', ?string $scopeId = null, string $permission = 'settings.manage'): void
    {
        $def = $this->definition($key);
        $this->access->assert($actorId, $permission);
        if (($def['critical'] ?? false) && !$this->access->isSuperadmin($actorId)) {
            throw new AccessDeniedException('Only a superadmin can change this critical setting.');
        }
        $this->db->transaction(function () use ($key, $scope, $scopeId): void {
            $cond = ['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId];
            $this->db->createCommand()->delete('settings', $cond)->execute();
            $this->audit->record('settings.reset', 'setting', $key, null, null, ['scope' => $scope, 'scope_id' => $scopeId], category: 'config', severity: 'notice');
        });
        $this->revision->bump();
        $this->cache = null;
    }

    /** @return list<array<string,mixed>> */
    public function history(string $key, string $scope = 'global', ?string $scopeId = null, int $limit = 50): array
    {
        $rows = (new Query($this->db))->select(['v.*', 'u.username AS changed_by_name'])->from(['v' => 'setting_versions'])
            ->leftJoin(['u' => 'users'], 'u.id = v.changed_by')
            ->where(['v.key' => $key, 'v.scope' => $scope, 'v.scope_id' => $scopeId])
            ->orderBy(['v.version' => SORT_DESC])->limit($limit)->all();
        $def = $this->definition($key);
        foreach ($rows as &$r) {
            $r['value'] = $def['type'] === 'secret' ? '***' : json_decode((string) $r['value'], true);
        }
        return $rows;
    }

    public function rollback(string $actorId, string $key, int $version, string $scope = 'global', ?string $scopeId = null): void
    {
        $row = (new Query($this->db))->from('setting_versions')
            ->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId, 'version' => $version])->one();
        if ($row === null) {
            throw new NotFoundException('Version not found.');
        }
        $def = $this->definition($key);
        $value = json_decode((string) $row['value'], true);
        if ($def['type'] === 'secret' && is_string($value)) {
            $value = $this->encrypter->decrypt($value, 'settings');
        }
        $this->set($actorId, $key, $value, $scope, $scopeId, "Rollback to version $version");
    }

    /** @return array<string, array<string, mixed>> group => [key => def+meta] */
    public function grouped(?string $tenantId = null): array
    {
        $out = [];
        foreach ($this->definitions as $key => $def) {
            $value = $this->get($key, $tenantId);
            $out[$def['group'] ?? 'Other'][$key] = $def + [
                'value' => $def['type'] === 'secret' ? ($value === null || $value === '' ? null : '********') : $value,
                'source' => $this->source($key, $tenantId),
                'is_set' => $value !== null && $value !== '',
            ];
        }
        return $out;
    }

    private function rawValue(string $key, string $scope, ?string $scopeId): mixed
    {
        $v = (new Query($this->db))->select('value')->from('settings')->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])->scalar();
        return $v === false || $v === null ? null : json_decode((string) $v, true);
    }

    private function validate(string $key, array $def, mixed $value): mixed
    {
        $fail = static fn(string $m) => throw ValidationException::single($key, $m);
        switch ($def['type']) {
            case 'bool':
                return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'int':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $fail('Must be an integer.');
                }
                $v = (int) $value;
                if (isset($def['min']) && $v < $def['min']) {
                    $fail("Must be ≥ {$def['min']}.");
                }
                if (isset($def['max']) && $v > $def['max']) {
                    $fail("Must be ≤ {$def['max']}.");
                }
                return $v;
            case 'enum':
                if (!in_array($value, $def['options'], true)) {
                    $fail('Invalid option.');
                }
                return $value;
            case 'json':
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $fail('Invalid JSON: ' . json_last_error_msg());
                    }
                    return $decoded;
                }
                return $value;
            case 'secret':
                return $value === null ? null : (string) $value;
            default:
                $v = trim((string) $value);
                if (isset($def['max']) && mb_strlen($v) > $def['max']) {
                    $fail("Maximum {$def['max']} characters.");
                }
                if (($def['format'] ?? null) === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $fail('Invalid e-mail address.');
                }
                if (($def['format'] ?? null) === 'url' && $v !== '' && (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $v))) {
                    $fail('Invalid URL.');
                }
                return $v;
        }
    }

    private function cast(array $def, string $raw): mixed
    {
        return match ($def['type']) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $raw,
            'json' => json_decode($raw, true),
            default => $raw,
        };
    }

    /** @return array<string, array<string,mixed>> */
    private function load(): array
    {
        $rev = $this->revision->current();
        if ($this->cache !== null && $this->cacheRevision === $rev) {
            return $this->cache;
        }
        $apcuKey = 'yep:settings:' . $rev;
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $cached = apcu_fetch($apcuKey, $ok);
            if ($ok) {
                $this->cacheRevision = $rev;
                return $this->cache = $cached;
            }
        }
        $map = [];
        foreach ((new Query($this->db))->select(['key', 'scope', 'scope_id', 'value'])->from('settings')->each(500) as $row) {
            $map[$row['scope'] . ':' . ($row['scope_id'] ?? '')][$row['key']] = json_decode((string) $row['value'], true);
        }
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            apcu_store($apcuKey, $map, 300);
        }
        $this->cacheRevision = $rev;
        return $this->cache = $map;
    }
}
