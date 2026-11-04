<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\User;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Organization tree (per tenant) administration.
 *
 * Rules:
 *  - permissions: users.view (read) / users.manage (write) — organizations are part of section 02;
 *  - tenant isolation: actors without `tenants.cross` only see/manage organizations of their own tenant,
 *    foreign objects are reported as non-existent (IDOR);
 *  - a parent must belong to the same tenant and must not be the organization itself or one of its
 *    descendants (cycle prevention; tree edits are serialized per tenant with an advisory lock);
 *  - an organization with children or assigned users cannot be deleted (archive it instead).
 */
final class OrganizationService
{
    public const TYPES = ['company', 'branch', 'department', 'division', 'team', 'other'];
    public const STATUSES = ['active', 'inactive', 'archived'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
    ) {}

    /** Tenant the actor is confined to, or null for cross-tenant actors. */
    public function tenantRestriction(User $actor): ?string
    {
        return $this->access->can($actor->id, 'tenants.cross') ? null : ($actor->tenantId ?? '00000000-0000-0000-0000-000000000000');
    }

    /**
     * Tenant whose tree is shown: restricted actors always get their own tenant; cross-tenant actors get
     * the requested one (when it exists) or their own/the first tenant.
     */
    public function resolveTenant(User $actor, ?string $requested): ?string
    {
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            return $restriction;
        }
        if ($requested !== null && $requested !== '' && Uuid::isValid($requested)
            && (new Query($this->db))->from('tenants')->where(['id' => $requested])->exists()) {
            return $requested;
        }
        if ($actor->tenantId !== null) {
            return $actor->tenantId;
        }
        $first = (new Query($this->db))->select('id')->from('tenants')->orderBy(['created_at' => SORT_ASC])->limit(1)->scalar();
        return $first === false || $first === null ? null : (string) $first;
    }

    /**
     * Depth-first ordered tree of a tenant's organizations (each row has `depth` and `user_count`).
     * With a search term the matches are returned flat (depth 0).
     *
     * @return list<array<string,mixed>>
     */
    public function tree(User $actor, ?string $tenantId, string $q = '', string $status = ''): array
    {
        $this->access->assert($actor->id, 'users.view');
        $tenantId = $this->resolveTenant($actor, $tenantId);
        if ($tenantId === null) {
            return [];
        }
        $query = (new Query($this->db))
            ->select(['o.*', new Expression('(SELECT count(*) FROM users u WHERE u.organization_id = o.id AND u.deleted_at IS NULL) AS user_count'),
                new Expression('(SELECT count(*) FROM organizations c WHERE c.parent_id = o.id) AS child_count')])
            ->from(['o' => 'organizations'])
            ->where(['o.tenant_id' => $tenantId])
            ->orderBy(['o.name' => SORT_ASC]);
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $query->andWhere(['o.status' => $status]);
        }
        $rows = $query->all();
        $q = trim($q);
        if ($q !== '') {
            $needle = mb_strtolower($q);
            return array_values(array_map(static fn($r) => $r + ['depth' => 0], array_filter($rows, static fn($r) =>
                str_contains(mb_strtolower((string) $r['name']), $needle) || str_contains(mb_strtolower((string) $r['code']), $needle))));
        }
        $children = [];
        $ids = array_column($rows, 'id');
        foreach ($rows as $r) {
            $parent = $r['parent_id'] !== null && in_array($r['parent_id'], $ids, true) ? (string) $r['parent_id'] : '';
            $children[$parent][] = $r;
        }
        $out = [];
        $walk = static function (string $parent, int $depth, array $seen) use (&$walk, &$out, $children): void {
            foreach ($children[$parent] ?? [] as $r) {
                if (isset($seen[$r['id']])) {
                    continue; // defensive: never loop on corrupt data
                }
                $out[] = $r + ['depth' => $depth];
                $walk((string) $r['id'], $depth + 1, $seen + [$r['id'] => true]);
            }
        };
        $walk('', 0, []);
        return $out;
    }

    /** @return array<string,string> id => indented label (for <select>), excluding $excludeSubtreeOf */
    public function options(User $actor, ?string $tenantId, ?string $excludeSubtreeOf = null): array
    {
        $exclude = $excludeSubtreeOf !== null ? array_flip($this->subtreeIds($excludeSubtreeOf)) : [];
        $out = [];
        foreach ($this->tree($actor, $tenantId) as $r) {
            if (!isset($exclude[$r['id']])) {
                $out[(string) $r['id']] = str_repeat('— ', (int) $r['depth']) . $r['name'] . ' (' . $r['code'] . ')';
            }
        }
        return $out;
    }

    public function get(User $actor, string $id): array
    {
        $this->access->assert($actor->id, 'users.view');
        return $this->load($actor, $id);
    }

    public function create(User $actor, array $input): array
    {
        $this->access->assert($actor->id, 'users.manage');
        $restriction = $this->tenantRestriction($actor);
        $tenantId = $restriction ?? trim((string) ($input['tenant_id'] ?? ''));
        if (!Uuid::isValid($tenantId) || !(new Query($this->db))->from('tenants')->where(['id' => $tenantId])->exists()) {
            throw ValidationException::single('tenant_id', 'Select a tenant.');
        }
        $data = $this->validate($input, true);
        $data['tenant_id'] = $tenantId;
        $data['id'] = Uuid::v7();

        return $this->db->transaction(function () use ($data): array {
            $this->lockTree($data['tenant_id']);
            if ($data['parent_id'] !== null) {
                $this->assertParent($data['tenant_id'], $data['parent_id'], null);
            }
            $this->write(fn() => $this->db->createCommand()->insert('organizations', $data)->execute());
            $row = $this->find($data['id']);
            $this->audit->record('organization.created', 'organization', $data['id'], null, $row, category: 'data');
            return $row;
        });
    }

    public function update(User $actor, string $id, array $input): array
    {
        $this->access->assert($actor->id, 'users.manage');
        $before = $this->load($actor, $id);
        $data = $this->validate($input, false);

        return $this->db->transaction(function () use ($id, $before, $data): array {
            $this->lockTree((string) $before['tenant_id']);
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null && $data['parent_id'] !== $before['parent_id']) {
                $this->assertParent((string) $before['tenant_id'], $data['parent_id'], $id);
            }
            $this->write(fn() => $this->db->createCommand()->update('organizations', $data, ['id' => $id])->execute());
            $after = $this->find($id);
            $this->audit->record('organization.updated', 'organization', $id, $before, $after, category: 'data');
            return $after;
        });
    }

    public function delete(User $actor, string $id): void
    {
        $this->access->assert($actor->id, 'users.manage');
        $org = $this->load($actor, $id);
        $this->db->transaction(function () use ($org): void {
            $this->lockTree((string) $org['tenant_id']);
            if ((new Query($this->db))->from('organizations')->where(['parent_id' => $org['id']])->exists()) {
                throw new ConflictException('The organization has child units. Move or delete them first.', 'has_children');
            }
            if ((new Query($this->db))->from('users')->where(['organization_id' => $org['id'], 'deleted_at' => null])->exists()) {
                throw new ConflictException('Users are assigned to this organization. Reassign them or archive the organization instead.', 'has_users');
            }
            $this->db->createCommand()->delete('organizations', ['id' => $org['id']])->execute();
            $this->audit->record('organization.deleted', 'organization', (string) $org['id'], $org, null, category: 'data', severity: 'notice');
        });
    }

    /**
     * Ensures an organization may be assigned to a user of $tenantId (same tenant, visible to the actor).
     */
    public function assertAssignable(User $actor, ?string $tenantId, ?string $organizationId): void
    {
        if ($organizationId === null || $organizationId === '') {
            return;
        }
        try {
            $org = $this->load($actor, $organizationId);
        } catch (NotFoundException) {
            throw ValidationException::single('organization_id', 'Unknown organization.');
        }
        if ($org['tenant_id'] !== $tenantId) {
            throw ValidationException::single('organization_id', 'The organization belongs to another tenant.');
        }
    }

    public function load(User $actor, string $id): array
    {
        $row = Uuid::isValid($id) ? $this->find($id) : null;
        $restriction = $this->tenantRestriction($actor);
        if ($row === null || ($restriction !== null && $row['tenant_id'] !== $restriction)) {
            throw new NotFoundException('Organization not found.');
        }
        return $row;
    }

    /** @return list<string> the organization and all of its descendants */
    public function subtreeIds(string $id): array
    {
        if (!Uuid::isValid($id)) {
            return [];
        }
        return array_map('strval', $this->db->createCommand(
            'WITH RECURSIVE sub AS (SELECT id FROM organizations WHERE id = :id
                 UNION SELECT o.id FROM organizations o JOIN sub ON o.parent_id = sub.id)
             SELECT id FROM sub',
            [':id' => $id],
        )->queryColumn());
    }

    private function find(string $id): ?array
    {
        $row = (new Query($this->db))->from('organizations')->where(['id' => $id])->one();
        return $row ?: null;
    }

    private function assertParent(string $tenantId, string $parentId, ?string $selfId): void
    {
        $parent = $this->find($parentId);
        if ($parent === null || $parent['tenant_id'] !== $tenantId) {
            throw ValidationException::single('parent_id', 'The parent organization must belong to the same tenant.');
        }
        if ($selfId !== null && in_array($parentId, $this->subtreeIds($selfId), true)) {
            throw ValidationException::single('parent_id', 'An organization cannot be moved under itself or one of its descendants.');
        }
    }

    private function lockTree(string $tenantId): void
    {
        $this->db->createCommand("SELECT pg_advisory_xact_lock(hashtext('org-tree:' || :t))", [':t' => $tenantId])->execute();
    }

    private function write(callable $fn): void
    {
        try {
            $fn();
        } catch (IntegrityException) {
            throw ValidationException::single('code', 'An organization with this code already exists in the tenant.');
        }
    }

    private function validate(array $in, bool $creating): array
    {
        $errors = [];
        $data = [];
        $str = static fn(string $k): ?string => array_key_exists($k, $in) ? trim((string) $in[$k]) : null;

        if ($creating || array_key_exists('code', $in)) {
            $code = $str('code') ?? '';
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $code)) {
                $errors['code'][] = 'Code must be 1–64 characters: letters, digits, . _ -';
            }
            $data['code'] = $code;
        }
        if ($creating || array_key_exists('name', $in)) {
            $name = $str('name') ?? '';
            if ($name === '' || mb_strlen($name) > 255) {
                $errors['name'][] = 'Name is required (max 255 characters).';
            }
            $data['name'] = $name;
        }
        if (array_key_exists('inn', $in)) {
            $inn = $str('inn');
            if ($inn !== null && $inn !== '' && !preg_match('/^[0-9]{9}$/', $inn)) {
                $errors['inn'][] = 'INN must be exactly 9 digits.';
            }
            $data['inn'] = $inn === '' ? null : $inn;
        }
        if ($creating || array_key_exists('type', $in)) {
            $type = $str('type') ?: 'department';
            if (!in_array($type, self::TYPES, true)) {
                $errors['type'][] = 'Unknown type.';
            }
            $data['type'] = $type;
        }
        if ($creating || array_key_exists('status', $in)) {
            $status = $str('status') ?: 'active';
            if (!in_array($status, self::STATUSES, true)) {
                $errors['status'][] = 'Unknown status.';
            }
            $data['status'] = $status;
        }
        if ($creating || array_key_exists('parent_id', $in)) {
            $parent = $str('parent_id');
            if ($parent !== null && $parent !== '' && !Uuid::isValid($parent)) {
                $errors['parent_id'][] = 'Invalid reference.';
            }
            $data['parent_id'] = $parent === '' ? null : $parent;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $data;
    }
}
