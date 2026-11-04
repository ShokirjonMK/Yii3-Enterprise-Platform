<?php

declare(strict_types=1);

namespace App\Platform\FeatureFlags;

use App\Environment;
use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Monitoring\HealthChecker;
use App\Platform\Settings\ConfigRevision;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Sql;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Feature flags with health status, dependency validation, tenant targeting, percentage rollout
 * (sticky per user) and scheduled enable/disable. Unknown flags evaluate to false (default deny).
 *
 * Lifecycle status values: enabled, disabled, unconfigured, healthy, degraded, failed, maintenance.
 * A flag in failed/maintenance/unconfigured state is OFF regardless of `enabled`.
 */
final class FeatureFlagService
{
    public const STATUSES = ['enabled', 'disabled', 'unconfigured', 'healthy', 'degraded', 'failed', 'maintenance'];
    private const OFF_STATUSES = ['unconfigured', 'failed', 'maintenance', 'disabled'];

    /** @var array<string, array<string,mixed>>|null */
    private ?array $flags = null;
    private int $rev = -1;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ConfigRevision $revision,
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
        private readonly HealthChecker $health,
    ) {}

    public function isEnabled(string $code, ?string $tenantId = null, ?string $userId = null): bool
    {
        $flag = $this->all()[$code] ?? null;
        if ($flag === null || !$flag['enabled'] || in_array($flag['status'], self::OFF_STATUSES, true)) {
            return false;
        }
        if ($code === 'devtools.gii' && Environment::isProd()) {
            return false;
        }
        $now = new DateTimeImmutable();
        if ($flag['scheduled_enable_at'] !== null && new DateTimeImmutable($flag['scheduled_enable_at']) > $now) {
            return false;
        }
        if ($flag['scheduled_disable_at'] !== null && new DateTimeImmutable($flag['scheduled_disable_at']) <= $now) {
            return false;
        }
        if ($tenantId !== null && array_key_exists($tenantId, $flag['overrides'])) {
            return $flag['overrides'][$tenantId];
        }
        if ($flag['tenant_allowlist'] !== [] && ($tenantId === null || !in_array($tenantId, $flag['tenant_allowlist'], true))) {
            return false;
        }
        if ($flag['rollout_percentage'] < 100) {
            if ($userId === null) {
                return false;
            }
            return (crc32($code . ':' . $userId) % 100) < $flag['rollout_percentage'];
        }
        return true;
    }

    /** @return array<string, array<string,mixed>> */
    public function all(): array
    {
        $rev = $this->revision->current();
        if ($this->flags !== null && $this->rev === $rev) {
            return $this->flags;
        }
        $key = 'yep:flags:' . $rev;
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            $cached = apcu_fetch($key, $ok);
            if ($ok) {
                $this->rev = $rev;
                return $this->flags = $cached;
            }
        }
        $flags = [];
        foreach ((new Query($this->db))->from('feature_flags')->orderBy('code')->all() as $row) {
            $flags[$row['code']] = [
                'id' => $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'description' => $row['description'],
                'enabled' => Sql::bool($row['enabled']),
                'status' => $row['status'],
                'rollout_percentage' => (int) $row['rollout_percentage'],
                'tenant_allowlist' => Sql::json($row['tenant_allowlist']),
                'dependencies' => Sql::json($row['dependencies']),
                'is_critical' => Sql::bool($row['is_critical']),
                'scheduled_enable_at' => $row['scheduled_enable_at'],
                'scheduled_disable_at' => $row['scheduled_disable_at'],
                'version' => (int) $row['version'],
                'updated_at' => $row['updated_at'],
                'overrides' => [],
            ];
        }
        $byId = array_column($flags, 'code', 'id');
        foreach ((new Query($this->db))->from('feature_flag_overrides')->all() as $o) {
            if (isset($byId[$o['flag_id']])) {
                $flags[$byId[$o['flag_id']]]['overrides'][$o['tenant_id']] = Sql::bool($o['enabled']);
            }
        }
        if (function_exists('apcu_store') && ini_get('apc.enabled')) {
            apcu_store($key, $flags, 300);
        }
        $this->rev = $rev;
        return $this->flags = $flags;
    }

    public function get(string $code): array
    {
        return $this->all()[$code] ?? throw new NotFoundException("Unknown feature flag \"$code\".");
    }

    public function enable(string $actorId, string $code): void
    {
        $this->access->assert($actorId, 'settings.manage');
        $flag = $this->get($code);
        $problems = $this->dependencyProblems($flag);
        if ($problems !== []) {
            throw new ValidationException(['dependencies' => $problems], 'Cannot enable: ' . implode('; ', $problems));
        }
        $this->write($actorId, $flag, ['enabled' => true, 'status' => 'enabled'], 'feature_flag.enabled');
    }

    public function disable(string $actorId, string $code): void
    {
        $this->access->assert($actorId, 'settings.manage');
        $flag = $this->get($code);
        if ($flag['is_critical']) {
            throw new ConflictException('This is a critical security control and cannot be disabled through feature flags.', 'critical_flag');
        }
        $this->write($actorId, $flag, ['enabled' => false, 'status' => 'disabled'], 'feature_flag.disabled');
    }

    /**
     * @param array{status?:string, rollout_percentage?:int|string, tenant_allowlist?:list<string>|string, scheduled_enable_at?:?string, scheduled_disable_at?:?string} $input
     */
    public function configure(string $actorId, string $code, array $input): void
    {
        $this->access->assert($actorId, 'settings.manage');
        $flag = $this->get($code);
        $data = [];
        $errors = [];
        if (isset($input['status'])) {
            if (!in_array($input['status'], self::STATUSES, true)) {
                $errors['status'][] = 'Unknown status.';
            } elseif ($flag['is_critical'] && in_array($input['status'], self::OFF_STATUSES, true)) {
                $errors['status'][] = 'Critical controls cannot be switched off.';
            }
            $data['status'] = $input['status'];
            $data['enabled'] = !in_array($input['status'], ['disabled'], true) && ($flag['enabled'] || $input['status'] === 'enabled');
        }
        if (isset($input['rollout_percentage'])) {
            $p = filter_var($input['rollout_percentage'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
            if ($p === false) {
                $errors['rollout_percentage'][] = 'Must be 0–100.';
            }
            $data['rollout_percentage'] = (int) $p;
        }
        if (array_key_exists('tenant_allowlist', $input)) {
            $list = is_array($input['tenant_allowlist']) ? $input['tenant_allowlist'] : array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $input['tenant_allowlist']) ?: []));
            foreach ($list as $t) {
                if (!\App\Shared\Domain\Uuid::isValid($t)) {
                    $errors['tenant_allowlist'][] = "Invalid tenant id: $t";
                }
            }
            $data['tenant_allowlist'] = json_encode(array_values($list));
        }
        foreach (['scheduled_enable_at', 'scheduled_disable_at'] as $f) {
            if (array_key_exists($f, $input)) {
                $v = $input[$f];
                if ($v === null || $v === '') {
                    $data[$f] = null;
                } elseif (strtotime((string) $v) === false) {
                    $errors[$f][] = 'Invalid date/time.';
                } else {
                    $data[$f] = (new DateTimeImmutable((string) $v))->format(DATE_ATOM);
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->write($actorId, $flag, $data, 'feature_flag.configured');
    }

    public function setTenantOverride(string $actorId, string $code, string $tenantId, ?bool $enabled): void
    {
        $this->access->assert($actorId, 'settings.manage');
        $flag = $this->get($code);
        $this->db->transaction(function () use ($flag, $tenantId, $enabled, $actorId): void {
            if ($enabled === null) {
                $this->db->createCommand()->delete('feature_flag_overrides', ['flag_id' => $flag['id'], 'tenant_id' => $tenantId])->execute();
            } else {
                $this->db->createCommand()->upsert('feature_flag_overrides', ['flag_id' => $flag['id'], 'tenant_id' => $tenantId, 'enabled' => $enabled])->execute();
            }
            $this->audit->record('feature_flag.tenant_override', 'feature_flag', $flag['code'], null, ['tenant_id' => $tenantId, 'enabled' => $enabled], category: 'config', actorId: $actorId);
        });
        $this->revision->bump();
        $this->flags = null;
    }

    /**
     * @return list<string> human-readable unmet dependencies
     */
    public function dependencyProblems(array $flag): array
    {
        $problems = [];
        foreach ($flag['dependencies'] as $dep) {
            [$type, $name] = array_pad(explode(':', (string) $dep, 2), 2, '');
            switch ($type) {
                case 'service':
                    $r = $this->health->checkAll([$name])[$name] ?? null;
                    if ($r === null || $r['status'] !== HealthChecker::UP) {
                        $problems[] = "service \"$name\" is " . ($r['status'] ?? 'unknown');
                    }
                    break;
                case 'channel':
                    $ok = (new Query($this->db))->from('notification_channels')->where(['code' => $name, 'enabled' => true])->exists();
                    if (!$ok) {
                        $problems[] = "notification channel \"$name\" is not configured/enabled";
                    }
                    break;
                case 'flag':
                    if (!$this->isEnabled($name)) {
                        $problems[] = "flag \"$name\" is disabled";
                    }
                    break;
                case 'env':
                    if ($name === 'non-prod' && Environment::isProd()) {
                        $problems[] = 'not allowed in production';
                    }
                    break;
            }
        }
        return $problems;
    }

    /** Applies scheduled windows (called every minute by the scheduler). @return int changed flags */
    public function applySchedules(): int
    {
        $changed = $this->db->createCommand(
            "UPDATE feature_flags SET enabled = true, status = 'enabled', scheduled_enable_at = NULL, version = version + 1
             WHERE scheduled_enable_at IS NOT NULL AND scheduled_enable_at <= now()",
        )->execute();
        $changed += $this->db->createCommand(
            "UPDATE feature_flags SET enabled = false, status = 'disabled', scheduled_disable_at = NULL, version = version + 1
             WHERE scheduled_disable_at IS NOT NULL AND scheduled_disable_at <= now() AND NOT is_critical",
        )->execute();
        if ($changed > 0) {
            $this->audit->record('feature_flag.schedule_applied', 'feature_flag', null, null, null, ['changed' => $changed], category: 'config');
            $this->revision->bump();
            $this->flags = null;
        }
        return $changed;
    }

    private function write(string $actorId, array $flag, array $data, string $action): void
    {
        $data['version'] = new Expression('version + 1');
        $data['updated_by'] = $actorId;
        $this->db->transaction(function () use ($flag, $data, $action, $actorId): void {
            $affected = $this->db->createCommand()->update('feature_flags', $data, ['id' => $flag['id'], 'version' => $flag['version']])->execute();
            if ($affected === 0) {
                throw new ConflictException('The flag was modified concurrently. Reload and try again.', 'stale_version');
            }
            $after = $data;
            unset($after['version'], $after['updated_by']);
            $this->audit->record($action, 'feature_flag', $flag['code'],
                array_intersect_key($flag, $after), $after, category: 'config', severity: 'notice', actorId: $actorId);
        });
        $this->revision->bump();
        $this->flags = null;
    }
}
