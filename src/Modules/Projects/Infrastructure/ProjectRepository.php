<?php

declare(strict_types=1);

namespace App\Modules\Projects\Infrastructure;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

final class ProjectRepository
{
    /** Public sort key => SQL expression (whitelist). */
    public const SORTABLE = [
        'code' => 'p.code',
        'name' => 'p.name',
        'status' => 'p.status',
        // expression in parentheses: the query builder leaves it unquoted
        'priority' => "(CASE p.priority WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END)",
        'budget' => 'p.budget',
        'starts_on' => 'p.starts_on',
        'due_on' => 'p.due_on',
        'created_at' => 'p.created_at',
        'updated_at' => 'p.updated_at',
    ];

    public const FILTERS = ['status', 'priority', 'owner_id', 'tag', 'tenant_id', 'include_archived'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function find(string $id): ?Project
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = (new Query($this->db))->from('projects')->where(['id' => strtolower($id)])->one();
        return $row === null ? null : Project::fromRow($row);
    }

    /** Row lock for read-modify-write inside a transaction. */
    public function findForUpdate(string $id): ?Project
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->db->createCommand('SELECT * FROM projects WHERE id = :id FOR UPDATE', [':id' => strtolower($id)])->queryOne();
        return $row === null ? null : Project::fromRow($row);
    }

    /**
     * @param array{q?:string,status?:string,priority?:string,owner_id?:string,tag?:string,tenant_id?:string,include_archived?:mixed,member_id?:string} $filters
     */
    public function search(array $filters, int $page, int $perPage, ?string $sort, ?string $restrictTenantId): Page
    {
        $q = (new Query($this->db))
            ->select([
                'p.id', 'p.tenant_id', 'p.code', 'p.name', 'p.description', 'p.status', 'p.priority', 'p.owner_id', 'p.budget',
                'p.starts_on', 'p.due_on', 'p.tags', 'p.version', 'p.created_by', 'p.created_at', 'p.updated_at', 'p.archived_at',
                'COALESCE(NULLIF(u.full_name, \'\'), u.username) AS owner_name', 't.name AS tenant_name',
            ])
            ->from(['p' => 'projects'])
            ->leftJoin(['u' => 'users'], 'u.id = p.owner_id')
            ->leftJoin(['t' => 'tenants'], 't.id = p.tenant_id');

        if ($restrictTenantId !== null) {
            $q->andWhere(['p.tenant_id' => $restrictTenantId]);
        } elseif (($filters['tenant_id'] ?? '') !== '' && Uuid::isValid((string) $filters['tenant_id'])) {
            $q->andWhere(['p.tenant_id' => $filters['tenant_id']]);
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $statuses = array_values(array_intersect(explode(',', $status), ProjectStatus::values()));
            $q->andWhere(['p.status' => $statuses === [] ? ['__none__'] : $statuses]);
        } elseif (!Sql::bool($filters['include_archived'] ?? false)) {
            $q->andWhere(['<>', 'p.status', ProjectStatus::Archived->value]);
        }
        if (($filters['priority'] ?? '') !== '') {
            $q->andWhere(['p.priority' => explode(',', (string) $filters['priority'])]);
        }
        if (($filters['owner_id'] ?? '') !== '' && Uuid::isValid((string) $filters['owner_id'])) {
            $q->andWhere(['p.owner_id' => $filters['owner_id']]);
        }
        if (($filters['member_id'] ?? '') !== '' && Uuid::isValid((string) $filters['member_id'])) {
            $q->andWhere(new Expression(
                '(p.owner_id = :mid OR EXISTS (SELECT 1 FROM project_members m WHERE m.project_id = p.id AND m.user_id = :mid))',
                [':mid' => $filters['member_id']],
            ));
        }
        if (($filters['tag'] ?? '') !== '') {
            $q->andWhere(new Expression('p.tags @> CAST(:tag AS jsonb)', [':tag' => json_encode([mb_strtolower((string) $filters['tag'])])]));
        }
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            // Full-text match on the generated tsvector OR substring match on code/name (pg_trgm index)
            $q->andWhere(['or',
                new Expression("p.search @@ plainto_tsquery('simple', :fts)", [':fts' => mb_substr($term, 0, 200)]),
                Sql::search($term, ['p.code', 'p.name']),
            ]);
        }

        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, '-created_at')
            ->map(static function (array $r): array {
                $r['tags'] = Sql::json($r['tags']);
                return $r;
            });
    }

    public function insert(Project $p): void
    {
        $row = $p->toRow();
        $row['created_by'] = $p->createdBy;
        $row['version'] = 1;
        try {
            $this->db->createCommand()->insert('projects', $row)->execute();
        } catch (IntegrityException $e) {
            throw $this->integrity($e);
        }
    }

    /**
     * Optimistic-locked update: succeeds only when the stored version equals $expectedVersion.
     *
     * @return int the new version
     */
    public function update(Project $p, int $expectedVersion): int
    {
        $row = $p->toRow();
        unset($row['id'], $row['tenant_id']);
        $row['version'] = new Expression('version + 1');
        try {
            $affected = $this->db->createCommand()->update('projects', $row, ['id' => $p->id, 'version' => $expectedVersion])->execute();
        } catch (IntegrityException $e) {
            throw $this->integrity($e);
        }
        if ($affected === 0) {
            throw new ConflictException('The project was modified by someone else. Reload and try again.', 'stale_version');
        }
        return $expectedVersion + 1;
    }

    public function addMember(string $projectId, string $userId, string $role, ?string $addedBy): void
    {
        $this->db->createCommand(
            'INSERT INTO project_members (project_id, user_id, role, added_by) VALUES (:p, :u, :r, :by)
             ON CONFLICT (project_id, user_id) DO UPDATE SET role = EXCLUDED.role',
            [':p' => $projectId, ':u' => $userId, ':r' => $role, ':by' => $addedBy],
        )->execute();
    }

    public function removeMember(string $projectId, string $userId): bool
    {
        return $this->db->createCommand()->delete('project_members', ['project_id' => $projectId, 'user_id' => $userId])->execute() > 0;
    }

    /** @return list<array{user_id:string, role:string, username:string, full_name:string, email:?string, created_at:string}> */
    public function members(string $projectId): array
    {
        return (new Query($this->db))
            ->select(['m.user_id', 'm.role', 'u.username', 'u.full_name', 'u.email', 'm.created_at'])
            ->from(['m' => 'project_members'])
            ->innerJoin(['u' => 'users'], 'u.id = m.user_id')
            ->where(['m.project_id' => $projectId])
            ->orderBy(['m.role' => SORT_ASC, 'u.username' => SORT_ASC])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    public function activity(string $projectId, int $limit = 50): array
    {
        return (new Query($this->db))->from('project_activity')->where(['project_id' => $projectId])
            ->orderBy(['occurred_at' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->all();
    }

    /** Audit trail fallback (when the read model has not caught up yet / consumer not running). */
    public function auditTrail(string $projectId, int $limit = 50): array
    {
        return (new Query($this->db))->select(['occurred_at', 'action', 'actor_id', 'actor_name', 'metadata'])
            ->from('audit_log')->where(['entity_type' => 'project', 'entity_id' => $projectId])
            ->orderBy(['occurred_at' => SORT_DESC])->limit($limit)->all();
    }

    /** @return array<string,int> status => count */
    public function countsByStatus(?string $restrictTenantId): array
    {
        $q = (new Query($this->db))->select(['status', 'n' => new Expression('count(*)')])->from('projects')->groupBy('status');
        if ($restrictTenantId !== null) {
            $q->where(['tenant_id' => $restrictTenantId]);
        }
        $out = [];
        foreach ($q->all() as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    private function integrity(IntegrityException $e): \Throwable
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'projects_tenant_code_uq')) {
            return new \App\Shared\Domain\Exception\ValidationException(['code' => ['A project with this code already exists.']]);
        }
        if (str_contains($msg, 'projects_owner_id_fkey')) {
            return new \App\Shared\Domain\Exception\ValidationException(['owner_id' => ['Unknown owner.']]);
        }
        return new ConflictException('The project violates a data constraint.', 'constraint_violation');
    }
}
