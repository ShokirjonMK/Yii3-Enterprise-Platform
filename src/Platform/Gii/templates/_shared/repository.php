<?php

declare(strict_types=1);

/**
 * Template: repository (src/Modules/<Module>/Infrastructure/<Entity>Repository.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$sortable = [];
foreach ($m->fields as $f) {
    if ($f->isSortable()) {
        $sortable[] = '        ' . $q($f->name) . ' => ' . $q('e.' . $f->name) . ',';
    }
}
$sortable[] = "        'created_at' => 'e.created_at',";
$sortable[] = "        'updated_at' => 'e.updated_at',";
$filters = array_map(static fn($f) => $f->name, $m->filterable());
$filters[] = 'tenant_id';

$select = ["'e.*'"];
$joins = [];
foreach ($m->ofType('user') as $f) {
    $a = 'u_' . $f->name;
    $select[] = $q("COALESCE(NULLIF($a.full_name, ''), $a.username) AS {$f->name}_name");
    $joins[] = "            ->leftJoin(['$a' => 'users'], '$a.id = e.{$f->name}')";
}
$filterCode = [];
foreach ($m->filterable() as $f) {
    $k = $q($f->name);
    if ($f->type === 'enum') {
        $const = $f->isStatus() ? "{$m->entity}Status::values()" : "{$m->entity}Rules::{$f->constName()}_OPTIONS";
        $filterCode[] = <<<PHP
        if ((string) (\$filters[$k] ?? '') !== '') {
            \$wanted = array_values(array_intersect(explode(',', (string) \$filters[$k]), $const));
            \$q->andWhere(['e.{$f->name}' => \$wanted === [] ? ['__none__'] : \$wanted]);
        }
PHP;
    } elseif ($f->type === 'user') {
        $filterCode[] = <<<PHP
        if ((string) (\$filters[$k] ?? '') !== '' && Uuid::isValid((string) \$filters[$k])) {
            \$q->andWhere(['e.{$f->name}' => strtolower((string) \$filters[$k])]);
        }
PHP;
    } else {
        $filterCode[] = <<<PHP
        if ((string) (\$filters[$k] ?? '') !== '') {
            \$q->andWhere(['e.{$f->name}' => Sql::bool(\$filters[$k])]);
        }
PHP;
    }
}
$search = $m->searchable();
if ($search !== []) {
    $cols = '[' . implode(', ', array_map(static fn($f) => $q('e.' . $f->name), $search)) . ']';
    $filterCode[] = <<<PHP
        \$term = trim((string) (\$filters['q'] ?? ''));
        if (\$term !== '') {
            \$q->andWhere(Sql::search(mb_substr(\$term, 0, 200), $cols));
        }
PHP;
}
$integrity = [];
foreach ($m->unique() as $f) {
    $integrity[] = "        if (str_contains(\$msg, '{$m->code}_tenant_{$f->name}_uq')) {\n            return new ValidationException([" . $q($f->name) . " => [" . $q('A ' . mb_strtolower($m->entityLabel) . ' with this ' . mb_strtolower($f->label()) . ' already exists.') . "]]);\n        }";
}
foreach ($m->ofType('user') as $f) {
    $integrity[] = "        if (str_contains(\$msg, '{$m->code}_{$f->name}_fkey')) {\n            return new ValidationException([" . $q($f->name) . " => [" . $q('Unknown user.') . "]]);\n        }";
}
$statusMethods = $m->hasStatus() ? <<<'PHP'

    /** @return array<string,int> status => count */
    public function countsByStatus(?string $restrictTenantId): array
    {
        $q = (new Query($this->db))->select(['status', 'n' => new Expression('count(*)')])->from('{{table}}')
            ->where(['deleted_at' => null])->groupBy('status');
        if ($restrictTenantId !== null) {
            $q->andWhere(['tenant_id' => $restrictTenantId]);
        }
        $out = [];
        foreach ($q->all() as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }
PHP : '';
$activity = $m->has('event') ? <<<'PHP'

    /** Kafka read model ({{entity_snake}}_activity, maintained by {{Entity}}ActivityProjector). */
    public function activity(string $id, int $limit = 50): array
    {
        return (new Query($this->db))->from('{{entity_snake}}_activity')->where(['entity_id' => $id])
            ->orderBy(['occurred_at' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->all();
    }
PHP : '';
$uses = [
    'use App\Modules\{{Module}}\Domain\{{Entity}};',
];
if ($m->hasStatus()) {
    $uses[] = 'use App\Modules\{{Module}}\Domain\{{Entity}}Status;';
}
if (array_filter($m->filterable(), static fn($f) => $f->type === 'enum' && !$f->isStatus())) {
    $uses[] = 'use App\Modules\{{Module}}\Domain\{{Entity}}Rules;';
}
array_push(
    $uses,
    'use App\Shared\Domain\Exception\ConflictException;',
    'use App\Shared\Domain\Exception\ValidationException;',
    'use App\Shared\Domain\Uuid;',
    'use App\Shared\Infrastructure\Database\Page;',
    'use App\Shared\Infrastructure\Database\Paginator;',
);
if ($search !== [] || array_filter($m->filterable(), static fn($f) => $f->type === 'bool')) {
    $uses[] = 'use App\Shared\Infrastructure\Database\Sql;';
}
array_push($uses, 'use Throwable;', 'use Yiisoft\Db\Connection\ConnectionInterface;', 'use Yiisoft\Db\Exception\IntegrityException;', 'use Yiisoft\Db\Expression\Expression;', 'use Yiisoft\Db\Query\Query;');

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Infrastructure;

__USES__

/**
 * Persistence of {{entityLabelLower}} aggregates in "{{table}}". Soft-deleted rows are invisible to every read;
 * sorting is whitelisted ({@see SORTABLE}); updates are optimistic-locked on "version".
 */
final class {{Entity}}Repository
{
    /** Public sort key => SQL column (whitelist). */
    public const SORTABLE = [
__SORTABLE__
    ];

    public const FILTERS = __FILTERS__;

    public function __construct(private readonly ConnectionInterface $db) {}

    public function find(string $id): ?{{Entity}}
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = (new Query($this->db))->from('{{table}}')->where(['id' => strtolower($id), 'deleted_at' => null])->one();
        return $row === null ? null : {{Entity}}::fromRow($row);
    }

    /** Row lock for read-modify-write inside a transaction. */
    public function findForUpdate(string $id): ?{{Entity}}
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->db->createCommand('SELECT * FROM {{table}} WHERE id = :id AND deleted_at IS NULL FOR UPDATE', [':id' => strtolower($id)])->queryOne();
        return $row === null ? null : {{Entity}}::fromRow($row);
    }

    /** @param array<string,mixed> $filters q + FILTERS */
    public function search(array $filters, int $page, int $perPage, ?string $sort, ?string $restrictTenantId): Page
    {
        $q = (new Query($this->db))
            ->select([__SELECT__])
            ->from(['e' => '{{table}}'])
__JOINS__
            ->where(['e.deleted_at' => null]);
        if ($restrictTenantId !== null) {
            $q->andWhere(['e.tenant_id' => $restrictTenantId]);
        } elseif ((string) ($filters['tenant_id'] ?? '') !== '' && Uuid::isValid((string) $filters['tenant_id'])) {
            $q->andWhere(['e.tenant_id' => strtolower((string) $filters['tenant_id'])]);
        }
__FILTER_CODE__
        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, '-created_at');
    }

    public function insert({{Entity}} $entity): void
    {
        $row = $entity->toRow();
        $row['created_by'] = $entity->createdBy;
        $row['version'] = 1;
        try {
            $this->db->createCommand()->insert('{{table}}', $row)->execute();
        } catch (IntegrityException $e) {
            throw $this->integrity($e);
        }
    }

    /**
     * Optimistic-locked update: succeeds only when the stored version equals $expectedVersion.
     *
     * @return int the new version
     */
    public function update({{Entity}} $entity, int $expectedVersion): int
    {
        $row = $entity->toRow();
        unset($row['id'], $row['tenant_id']);
        $row['version'] = new Expression('version + 1');
        try {
            $affected = $this->db->createCommand()->update('{{table}}', $row, ['id' => $entity->id, 'version' => $expectedVersion, 'deleted_at' => null])->execute();
        } catch (IntegrityException $e) {
            throw $this->integrity($e);
        }
        if ($affected === 0) {
            throw new ConflictException('The {{entityLabelLower}} was modified by someone else. Reload and try again.', 'stale_version');
        }
        return $expectedVersion + 1;
    }
__STATUS_METHODS__
    /** Audit trail of one record (timeline fallback). */
    public function auditTrail(string $id, int $limit = 50): array
    {
        return (new Query($this->db))->select(['occurred_at', 'action', 'actor_id', 'actor_name'])
            ->from('audit_log')->where(['entity_type' => '{{entity_snake}}', 'entity_id' => $id])
            ->orderBy(['occurred_at' => SORT_DESC])->limit($limit)->all();
    }
__ACTIVITY__
    private function integrity(IntegrityException $e): Throwable
    {
        $msg = $e->getMessage();
__INTEGRITY__
        return new ConflictException('The {{entityLabelLower}} violates a data constraint.', 'constraint_violation');
    }
}

PHP, [
    '__USES__' => implode("\n", $uses),
    '__SORTABLE__' => implode("\n", $sortable),
    '__FILTERS__' => '[' . implode(', ', array_map($q, $filters)) . ']',
    '__SELECT__' => implode(', ', $select),
    '__JOINS__' => implode("\n", $joins),
    '__FILTER_CODE__' => implode("\n", $filterCode),
    '__STATUS_METHODS__' => $statusMethods,
    '__ACTIVITY__' => $activity,
    '__INTEGRITY__' => implode("\n", $integrity),
]);
