<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure;

use App\Platform\Identity\Domain\User;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

final class UserRepository
{
    public const SORTABLE = [
        'username' => 'u.username',
        'full_name' => 'u.full_name',
        'email' => 'u.email',
        'status' => 'u.status',
        'created_at' => 'u.created_at',
        'last_login_at' => 'u.last_login_at',
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function find(string $id): ?User
    {
        if (!self::isUuid($id)) {
            return null;
        }
        $row = (new Query($this->db))->from('users')->where(['id' => $id])->one();
        return $row === null ? null : User::fromRow($row);
    }

    public function findByLogin(string $login): ?User
    {
        $login = trim($login);
        $row = (new Query($this->db))
            ->from('users')
            ->where(['deleted_at' => null])
            ->andWhere(['or', ['=', new Expression('lower(username)'), mb_strtolower($login)], ['=', new Expression('lower(email)'), mb_strtolower($login)]])
            ->one();
        return $row === null ? null : User::fromRow($row);
    }

    /**
     * @param array{q?:string,status?:string,tenant_id?:string,organization_id?:string,role?:string} $filters
     */
    public function search(array $filters, int $page, int $perPage, ?string $sort, ?string $restrictTenantId = null): Page
    {
        $q = (new Query($this->db))
            ->select(['u.id', 'u.username', 'u.full_name', 'u.email', 'u.phone', 'u.status', 'u.auth_policy', 'u.mfa_enabled',
                'u.last_login_at', 'u.created_at', 'u.tenant_id', 'u.organization_id', 't.name AS tenant_name', 'o.name AS organization_name',
                new Expression("(SELECT string_agg(a.item_name, ',' ORDER BY a.item_name) FROM yii_rbac_assignment a WHERE a.user_id = u.id::text) AS roles")])
            ->from(['u' => 'users'])
            ->leftJoin(['t' => 'tenants'], 't.id = u.tenant_id')
            ->leftJoin(['o' => 'organizations'], 'o.id = u.organization_id')
            ->where(['u.deleted_at' => null]);

        if ($restrictTenantId !== null) {
            $q->andWhere(['u.tenant_id' => $restrictTenantId]);
        }
        if (($filters['q'] ?? '') !== '') {
            $term = '%' . Sql::escapeLike(mb_strtolower(trim((string) $filters['q']))) . '%';
            $q->andWhere(['or',
                ['like', new Expression('lower(u.username)'), $term, null],
                ['like', new Expression('lower(u.full_name)'), $term, null],
                ['like', new Expression('lower(u.email)'), $term, null],
            ]);
        }
        if (($filters['status'] ?? '') !== '') {
            $q->andWhere(['u.status' => $filters['status']]);
        }
        if (($filters['tenant_id'] ?? '') !== '' && self::isUuid((string) $filters['tenant_id'])) {
            $q->andWhere(['u.tenant_id' => $filters['tenant_id']]);
        }
        if (($filters['organization_id'] ?? '') !== '' && self::isUuid((string) $filters['organization_id'])) {
            $q->andWhere(['u.organization_id' => $filters['organization_id']]);
        }
        if (($filters['role'] ?? '') !== '') {
            $q->andWhere(new Expression('EXISTS (SELECT 1 FROM yii_rbac_assignment a WHERE a.user_id = u.id::text AND a.item_name = :role)', [':role' => $filters['role']]));
        }

        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, 'username');
    }

    public function insert(array $data): string
    {
        $data['id'] ??= Uuid::v7();
        try {
            $this->db->createCommand()->insert('users', self::encodeJson($data))->execute();
        } catch (\Yiisoft\Db\Exception\IntegrityException $e) {
            throw new ConflictException('A user with this username or e-mail already exists.', 'duplicate_user');
        }
        return $data['id'];
    }

    /**
     * Optimistic-locked update. Pass $expectedVersion to guard against lost updates.
     */
    public function update(string $id, array $data, ?int $expectedVersion = null): void
    {
        $condition = ['id' => $id];
        if ($expectedVersion !== null) {
            $condition['version'] = $expectedVersion;
        }
        $data['version'] = new Expression('version + 1');
        try {
            $affected = $this->db->createCommand()->update('users', self::encodeJson($data), $condition)->execute();
        } catch (\Yiisoft\Db\Exception\IntegrityException) {
            throw new ConflictException('A user with this username or e-mail already exists.', 'duplicate_user');
        }
        if ($expectedVersion !== null && $affected === 0) {
            throw new ConflictException('The record was modified by someone else. Reload and try again.', 'stale_version');
        }
    }

    public function countActiveWithRole(string $role, ?string $excludeUserId = null): int
    {
        $q = (new Query($this->db))
            ->from(['a' => 'yii_rbac_assignment'])
            ->innerJoin(['u' => 'users'], 'u.id::text = a.user_id')
            ->where(['a.item_name' => $role, 'u.status' => 'active', 'u.deleted_at' => null]);
        if ($excludeUserId !== null) {
            $q->andWhere(['<>', 'u.id', $excludeUserId]);
        }
        return (int) $q->count();
    }

    public function passwordHistory(string $userId, int $limit): array
    {
        return (new Query($this->db))->select('hash')->from('user_password_history')
            ->where(['user_id' => $userId])->orderBy(['created_at' => SORT_DESC])->limit($limit)->column();
    }

    public function addPasswordHistory(string $userId, string $hash): void
    {
        $this->db->createCommand()->insert('user_password_history', ['user_id' => $userId, 'hash' => $hash])->execute();
    }

    /** External identities (SSO links) of a user, without raw claims. */
    public function identities(string $userId): array
    {
        return (new Query($this->db))
            ->select(['i.id', 'i.provider_code', 'i.issuer', 'i.subject', 'i.email', 'i.linked_at', 'i.last_used_at', 'p.name AS provider_name', 'p.type AS provider_type'])
            ->from(['i' => 'user_identities'])
            ->leftJoin(['p' => 'sso_providers'], 'p.code = i.provider_code')
            ->where(['i.user_id' => $userId])
            ->orderBy(['i.linked_at' => SORT_DESC])
            ->all();
    }

    public function findIdentity(string $userId, string $identityId): ?array
    {
        if (!self::isUuid($identityId)) {
            return null;
        }
        return (new Query($this->db))->from('user_identities')->where(['id' => $identityId, 'user_id' => $userId])->one() ?: null;
    }

    public static function isUuid(string $v): bool
    {
        return Uuid::isValid($v);
    }

    private static function encodeJson(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($v)) {
                $data[$k] = $v;
            }
        }
        return $data;
    }
}
