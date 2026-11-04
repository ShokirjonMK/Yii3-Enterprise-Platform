<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Identity\Domain\User;
use App\Platform\Setup\PlatformSeeder;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Tenant administration (section 05).
 *
 *  - tenants.view to read, tenants.manage to change; creating/deleting tenants and changing their status
 *    additionally requires tenants.cross (they are cross-tenant operations by nature);
 *  - actors without tenants.cross only see their own tenant (foreign tenants → NotFound);
 *  - suspending/archiving a tenant revokes all interactive sessions and API tokens of its users;
 *  - the default tenant and the actor's own tenant cannot be suspended, archived or deleted.
 */
final class TenantService
{
    public const STATUSES = ['active', 'suspended', 'archived'];

    public const SORTABLE = [
        'code' => 't.code',
        'name' => 't.name',
        'status' => 't.status',
        'domain' => 't.domain',
        'users' => 'user_count',
        'created_at' => 't.created_at',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly FeatureFlagService $flags,
    ) {}

    public function tenantRestriction(User $actor): ?string
    {
        return $this->access->can($actor->id, 'tenants.cross') ? null : ($actor->tenantId ?? '00000000-0000-0000-0000-000000000000');
    }

    /** @param array{q?:string,status?:string} $filters */
    public function list(User $actor, array $filters, int $page, int $perPage, ?string $sort): Page
    {
        $this->access->assert($actor->id, 'tenants.view');
        $q = $this->baseQuery();
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            $q->andWhere(['t.id' => $restriction]);
        }
        if (($filters['q'] ?? '') !== '') {
            $q->andWhere(TextSearch::condition((string) $filters['q'], ['t.code', 't.name', 't.domain']));
        }
        if (($filters['status'] ?? '') !== '' && in_array($filters['status'], self::STATUSES, true)) {
            $q->andWhere(['t.status' => $filters['status']]);
        }
        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, 'name');
    }

    /** @return array<string,string> id => "name (code)" of tenants visible to the actor */
    public function options(User $actor): array
    {
        $q = (new Query($this->db))->select(['id', 'code', 'name'])->from('tenants')->orderBy(['name' => SORT_ASC]);
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            $q->where(['id' => $restriction]);
        }
        $out = [];
        foreach ($q->all() as $r) {
            $out[(string) $r['id']] = $r['name'] . ' (' . $r['code'] . ')';
        }
        return $out;
    }

    public function get(User $actor, string $id): array
    {
        $this->access->assert($actor->id, 'tenants.view');
        return $this->load($actor, $id);
    }

    /** @return array{active:int, suspended:int, archived:int, total:int} */
    public function counts(User $actor): array
    {
        $q = (new Query($this->db))->select(['status', 'n' => new Expression('count(*)')])->from('tenants')->groupBy('status');
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            $q->where(['id' => $restriction]);
        }
        $out = ['active' => 0, 'suspended' => 0, 'archived' => 0, 'total' => 0];
        foreach ($q->all() as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
        }
        return $out;
    }

    public function create(User $actor, array $input): array
    {
        $this->access->assert($actor->id, 'tenants.manage');
        $this->access->assert($actor->id, 'tenants.cross');
        $data = $this->validate($input, true);
        $data['id'] = Uuid::v7();
        return $this->db->transaction(function () use ($data): array {
            $this->write(fn() => $this->db->createCommand()->insert('tenants', $data)->execute());
            $row = $this->find($data['id']);
            $this->audit->record('tenant.created', 'tenant', $data['id'], null, $this->publicRow($row), category: 'data', severity: 'notice');
            return $row;
        });
    }

    public function update(User $actor, string $id, array $input): array
    {
        $this->access->assert($actor->id, 'tenants.manage');
        $before = $this->load($actor, $id);
        $data = $this->validate($input, false);
        return $this->db->transaction(function () use ($id, $before, $data): array {
            $this->write(fn() => $this->db->createCommand()->update('tenants', $data, ['id' => $id])->execute());
            $after = $this->find($id);
            $this->audit->record('tenant.updated', 'tenant', $id, $this->publicRow($before), $this->publicRow($after), category: 'data');
            return $after;
        });
    }

    /**
     * @return array{tenant:array, sessions_revoked:int, tokens_revoked:int}
     */
    public function setStatus(User $actor, string $id, string $status): array
    {
        $this->access->assert($actor->id, 'tenants.manage');
        $this->access->assert($actor->id, 'tenants.cross');
        if (!in_array($status, self::STATUSES, true)) {
            throw ValidationException::single('status', 'Unknown status.');
        }
        $before = $this->load($actor, $id);
        if ($before['status'] === $status) {
            return ['tenant' => $before, 'sessions_revoked' => 0, 'tokens_revoked' => 0];
        }
        if ($status !== 'active') {
            $this->guardCritical($actor, $before, $status === 'suspended' ? 'suspend' : 'archive');
        }
        return $this->db->transaction(function () use ($id, $before, $status): array {
            $this->db->createCommand()->update('tenants', ['status' => $status], ['id' => $id])->execute();
            $sessions = 0;
            $tokens = 0;
            if ($status !== 'active') {
                $sessions = $this->db->createCommand(
                    'UPDATE user_sessions SET revoked_at = now(), revoked_reason = :r
                     WHERE revoked_at IS NULL AND user_id IN (SELECT id FROM users WHERE tenant_id = :t)',
                    [':r' => 'tenant_' . $status, ':t' => $id],
                )->execute();
                $tokens = $this->db->createCommand(
                    'UPDATE api_tokens SET revoked_at = now()
                     WHERE revoked_at IS NULL AND user_id IN (SELECT id FROM users WHERE tenant_id = :t)',
                    [':t' => $id],
                )->execute();
            }
            $this->audit->record('tenant.status.changed', 'tenant', $id, ['status' => $before['status']], ['status' => $status],
                ['sessions_revoked' => $sessions, 'tokens_revoked' => $tokens], category: 'security', severity: $status === 'active' ? 'notice' : 'warning');
            return ['tenant' => $this->find($id), 'sessions_revoked' => $sessions, 'tokens_revoked' => $tokens];
        });
    }

    public function delete(User $actor, string $id): void
    {
        $this->access->assert($actor->id, 'tenants.manage');
        $this->access->assert($actor->id, 'tenants.cross');
        $tenant = $this->load($actor, $id);
        $this->guardCritical($actor, $tenant, 'delete');
        $this->db->transaction(function () use ($tenant): void {
            if ((new Query($this->db))->from('users')->where(['tenant_id' => $tenant['id']])->exists()) {
                throw new ConflictException('The tenant still has users (including archived ones). Archive the tenant instead.', 'has_users');
            }
            if ((new Query($this->db))->from('organizations')->where(['tenant_id' => $tenant['id']])->exists()) {
                throw new ConflictException('The tenant still has organizations. Delete them first or archive the tenant.', 'has_organizations');
            }
            $this->db->createCommand()->delete('tenants', ['id' => $tenant['id']])->execute();
            $this->audit->record('tenant.deleted', 'tenant', (string) $tenant['id'], $this->publicRow($tenant), null, category: 'data', severity: 'warning');
        });
    }

    /**
     * Feature flags with this tenant's override state.
     *
     * @return list<array{code:string, name:string, global:bool, override:?bool, effective:bool, is_critical:bool}>
     */
    public function flagOverrides(User $actor, string $id): array
    {
        $this->access->assert($actor->id, 'tenants.view');
        $tenant = $this->load($actor, $id);
        $out = [];
        foreach ($this->flags->all() as $code => $flag) {
            $out[] = [
                'code' => $code,
                'name' => (string) $flag['name'],
                'global' => $this->flags->isEnabled($code),
                'override' => array_key_exists($tenant['id'], $flag['overrides']) ? $flag['overrides'][$tenant['id']] : null,
                'effective' => $this->flags->isEnabled($code, (string) $tenant['id']),
                'is_critical' => (bool) $flag['is_critical'],
            ];
        }
        return $out;
    }

    /** $enabled = null removes the override (tenant inherits the global state). */
    public function setFlagOverride(User $actor, string $id, string $code, ?bool $enabled): void
    {
        $this->access->assert($actor->id, 'tenants.manage');
        $tenant = $this->load($actor, $id);
        $flag = $this->flags->get($code);
        if ($enabled === false && $flag['is_critical']) {
            throw new ConflictException('Critical security controls cannot be switched off for a tenant.', 'critical_flag');
        }
        $this->flags->setTenantOverride($actor->id, $code, (string) $tenant['id'], $enabled);
    }

    public function load(User $actor, string $id): array
    {
        $row = Uuid::isValid($id) ? $this->find($id) : null;
        $restriction = $this->tenantRestriction($actor);
        if ($row === null || ($restriction !== null && $row['id'] !== $restriction)) {
            throw new NotFoundException('Tenant not found.');
        }
        return $row;
    }

    private function guardCritical(User $actor, array $tenant, string $operation): void
    {
        if ($tenant['code'] === PlatformSeeder::DEFAULT_TENANT) {
            $done = ['suspend' => 'suspended', 'archive' => 'archived', 'delete' => 'deleted'][$operation] ?? $operation;
            throw new ConflictException("The default tenant cannot be $done.", 'default_tenant');
        }
        if ($actor->tenantId !== null && $actor->tenantId === $tenant['id']) {
            throw new ConflictException("You cannot $operation the tenant your own account belongs to.", 'own_tenant');
        }
    }

    private function baseQuery(): Query
    {
        return (new Query($this->db))
            ->select(['t.id', 't.code', 't.name', 't.status', 't.domain', 't.created_at', 't.updated_at',
                new Expression('(SELECT count(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS user_count'),
                new Expression("(SELECT count(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL AND u.status = 'active') AS active_user_count"),
                new Expression('(SELECT count(*) FROM organizations o WHERE o.tenant_id = t.id) AS organization_count')])
            ->from(['t' => 'tenants']);
    }

    private function find(string $id): ?array
    {
        return $this->baseQuery()->where(['t.id' => $id])->one() ?: null;
    }

    private function publicRow(?array $row): ?array
    {
        return $row === null ? null : array_intersect_key($row, array_flip(['id', 'code', 'name', 'status', 'domain']));
    }

    private function write(callable $fn): void
    {
        try {
            $fn();
        } catch (IntegrityException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'tenants_domain_uq')) {
                throw ValidationException::single('domain', 'Another tenant already uses this domain.');
            }
            throw ValidationException::single('code', 'A tenant with this code already exists.');
        }
    }

    private function validate(array $in, bool $creating): array
    {
        $errors = [];
        $data = [];
        if ($creating) {
            $code = strtolower(trim((string) ($in['code'] ?? '')));
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code)) {
                $errors['code'][] = 'Code must be 2–64 lowercase letters, digits, - or _.';
            }
            $data['code'] = $code;
        }
        if ($creating || array_key_exists('name', $in)) {
            $name = trim((string) ($in['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255) {
                $errors['name'][] = 'Name is required (max 255 characters).';
            }
            $data['name'] = $name;
        }
        if (array_key_exists('domain', $in)) {
            $domain = strtolower(trim((string) $in['domain']));
            if ($domain !== '' && (strlen($domain) > 253
                || !preg_match('/^(?=[a-z0-9-]{1,63}\.)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain))) {
                $errors['domain'][] = 'Enter a valid host name, e.g. tenant.example.uz';
            }
            $data['domain'] = $domain === '' ? null : $domain;
        }
        if ($creating) {
            $status = (string) ($in['status'] ?? 'active');
            if (!in_array($status, self::STATUSES, true)) {
                $errors['status'][] = 'Unknown status.';
            }
            $data['status'] = $status;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $data;
    }
}
