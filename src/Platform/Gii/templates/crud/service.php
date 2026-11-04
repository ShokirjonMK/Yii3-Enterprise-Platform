<?php

declare(strict_types=1);

/**
 * Template: application service (src/Modules/<Module>/Application/<Entity>Service.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$status = $m->hasStatus();
$event = $m->has('event');
$workflow = $m->has('workflow') && $status;
$notify = $m->has('notification');
$report = $m->has('report');
$users = $m->ofType('user');

$uses = [
    'use App\Modules\{{Module}}\Domain\{{Entity}};',
];
if ($event) {
    $uses[] = 'use App\Modules\{{Module}}\Domain\{{Entity}}Event;';
}
if ($status) {
    $uses[] = 'use App\Modules\{{Module}}\Domain\{{Entity}}Status;';
}
$uses[] = 'use App\Modules\{{Module}}\Infrastructure\{{Entity}}Repository;';
$uses[] = 'use App\Platform\Access\AccessService;';
$uses[] = 'use App\Platform\Audit\AuditLogger;';
$uses[] = 'use App\Platform\Identity\Domain\User;';
$uses[] = 'use App\Platform\Identity\Infrastructure\UserRepository;';
if ($event) {
    $uses[] = 'use App\Platform\Messaging\Outbox;';
}
if ($report) {
    $uses[] = 'use App\Platform\Queue\JobQueue;';
}
array_push(
    $uses,
    'use App\Shared\Domain\Exception\ConflictException;',
    'use App\Shared\Domain\Exception\NotFoundException;',
    'use App\Shared\Domain\Exception\ValidationException;',
    'use App\Shared\Domain\Uuid;',
    'use App\Shared\Infrastructure\Database\Page;',
    'use Yiisoft\Db\Connection\ConnectionInterface;',
    'use Yiisoft\Db\Query\Query;',
);

$ctor = [
    '        private readonly {{Entity}}Repository $repository,',
    '        private readonly UserRepository $users,',
    '        private readonly AccessService $access,',
    '        private readonly AuditLogger $audit,',
    '        private readonly ConnectionInterface $db,',
];
if ($event) {
    $ctor[] = '        private readonly Outbox $outbox,';
}
if ($workflow) {
    $ctor[] = '        private readonly {{Entity}}Workflow $workflow,';
}
if ($notify) {
    $ctor[] = '        private readonly {{Entity}}Notifier $notifier,';
}
if ($report) {
    $ctor[] = '        private readonly JobQueue $queue,';
}

$userResolve = '';
if ($users !== []) {
    $lines = [];
    foreach ($users as $f) {
        $k = $q($f->name);
        $L = $f->label();
        if ($f->required) {
            $lines[] = "        if (\$creating && (string) (\$input[$k] ?? '') === '') {\n            \$input[$k] = \$actor->id; // default: the acting user\n        }";
        }
        $lines[] = "        \$value = \$input[$k] ?? null;\n        if (is_string(\$value) && \$value !== '' && Uuid::isValid(\$value) && \$value !== \$actor->id) {\n            \$user = \$this->users->find(\$value);\n            if (\$user === null || !\$user->isActive() || \$user->tenantId !== \$tenantId) {\n                \$errors[$k][] = " . $q("$L must be an active user of the same tenant.") . ";\n            } else {\n                \$input[$k] = \$user->id;\n            }\n        }";
    }
    $userResolve = "\n    /**\n     * User references must be active users of the record's tenant; required ones default to the actor on create.\n     *\n     * @param array<string,mixed> \$input\n     * @return array<string,mixed>\n     */\n    private function resolveUsers(User \$actor, string \$tenantId, array \$input, bool \$creating): array\n    {\n        \$errors = [];\n" . implode("\n", $lines) . "\n        if (\$errors !== []) {\n            throw new ValidationException(\$errors);\n        }\n        return \$input;\n    }\n";
}

$statusMethods = '';
if ($status) {
    $statusMethods = <<<'PHP'

    /** Lifecycle transition (permission {{code}}.update); invalid moves → 409 invalid_transition. */
    public function changeStatus(User $actor, string $id, string $status, ?int $expectedVersion = null, string $comment = ''): {{Entity}}
    {
        $to = {{Entity}}Status::tryFrom($status);
        if ($to === null) {
            throw ValidationException::single('status', 'Status must be one of: ' . implode(', ', {{Entity}}Status::values()) . '.');
        }
        $this->access->assert($actor->id, '{{code}}.update');
        return $this->db->transaction(function () use ($actor, $id, $to, $expectedVersion, $comment): {{Entity}} {
            $entity = $this->loadForUpdate($actor, $id);
            $this->assertVersion($entity, $expectedVersion);
            $version = $entity->version();
            $from = $entity->changeStatus($to, $comment);
            $this->repository->update($entity, $version);
            $after = $this->repository->find($entity->id) ?? $entity;
            $this->audit->record('{{entity_snake}}.status_changed', '{{entity_snake}}', $entity->id, ['status' => $from->value], ['status' => $to->value],
                ['from' => $from->value, 'to' => $to->value, 'comment' => $comment], severity: $to->isTerminal() ? 'notice' : 'info');
            $this->publish($entity);__WORKFLOW_MIRROR____NOTIFY_STATUS__
            return $after;
        });
    }

    /** @return array<string,int> status => count (tenant scoped) */
    public function countsByStatus(User $actor): array
    {
        $this->access->assert($actor->id, '{{code}}.view');
        return $this->repository->countsByStatus($this->tenantRestriction($actor));
    }
PHP;
}

$reportMethods = '';
if ($report) {
    $reportMethods = <<<'PHP'

    /**
     * Enqueues the CSV report for the actor's tenant (background job, transactional enqueue).
     *
     * @return array{report_id:string, job_id:string}
     */
    public function requestReport(User $actor, array $filters = []): array
    {
        $this->access->assert($actor->id, '{{code}}.view');
        $tenantId = $this->resolveTenant($actor, $filters);
        $filters = array_intersect_key($filters, array_flip(['q', ...{{Entity}}Repository::FILTERS]));
        unset($filters['tenant_id']);
        return $this->db->transaction(function () use ($actor, $tenantId, $filters): array {
            $reportId = Uuid::v7();
            $this->db->createCommand()->insert('{{entity_snake}}_reports', [
                'id' => $reportId,
                'tenant_id' => $tenantId,
                'filters' => json_encode((object) $filters),
                'requested_by' => $actor->id,
            ])->execute();
            $jobId = $this->queue->push(self::REPORT_JOB, ['report_id' => $reportId, 'tenant_id' => $tenantId], [
                'queue' => 'default',
                'tenant_id' => $tenantId,
                'max_attempts' => 3,
                'timeout' => 600,
                'idempotency_key' => '{{code}}.report:' . $reportId,
            ]);
            $this->db->createCommand()->update('{{entity_snake}}_reports', ['job_id' => $jobId], ['id' => $reportId])->execute();
            $this->audit->record('{{entity_snake}}.report.requested', '{{entity_snake}}_report', $reportId, null, ['tenant_id' => $tenantId, 'filters' => $filters, 'job_id' => $jobId]);
            return ['report_id' => $reportId, 'job_id' => $jobId];
        });
    }

    /** @return array<string,mixed> report row joined with the job status */
    public function report(User $actor, string $reportId): array
    {
        $this->access->assert($actor->id, '{{code}}.view');
        if (!Uuid::isValid($reportId)) {
            throw new NotFoundException('Report not found.');
        }
        $row = (new Query($this->db))
            ->select(['r.*', 'job_status' => 'j.status', 'job_error' => 'j.last_error'])
            ->from(['r' => '{{entity_snake}}_reports'])->leftJoin(['j' => 'jobs'], 'j.id = r.job_id')
            ->where(['r.id' => strtolower($reportId)])->one();
        $restriction = $this->tenantRestriction($actor);
        if ($row === null || ($restriction !== null && $row['tenant_id'] !== $restriction)) {
            throw new NotFoundException('Report not found.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function recentReports(User $actor, int $limit = 5): array
    {
        $this->access->assert($actor->id, '{{code}}.view');
        $q = (new Query($this->db))->select(['id', 'status', 'rows_count', 'size_bytes', 'created_at', 'finished_at'])
            ->from('{{entity_snake}}_reports')->orderBy(['created_at' => SORT_DESC])->limit($limit);
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            $q->where(['tenant_id' => $restriction]);
        }
        return $q->all();
    }
PHP;
}

$activityBody = $event ? <<<'PHP'
        $items = $this->repository->activity($entity->id);
        if ($items !== []) {
            return ['source' => 'read_model', 'items' => $items];
        }
PHP : '';

$publish = $event ? <<<'PHP'

    /** Writes the aggregate's pending domain events to the transactional outbox (same DB transaction). */
    private function publish({{Entity}} $entity): void
    {
        foreach ($entity->pullEvents() as $event) {
            $this->outbox->record({{Entity}}Event::TOPIC, $event['type'], {{Entity}}Event::AGGREGATE, $entity->id, {{Entity}}Event::payload($entity, $event['payload']));
        }
    }
PHP : <<<'PHP'

    /** Without the Kafka Event generator domain events are not published; they are only drained. */
    private function publish({{Entity}} $entity): void
    {
        $entity->pullEvents();
    }
PHP;

$statusMethods = strtr($statusMethods, [
    '__WORKFLOW_MIRROR__' => $workflow ? "\n            \$this->workflow->mirror(\$after, \$from, \$to, \$actor->id, \$comment);" : '',
    '__NOTIFY_STATUS__' => $notify ? "\n            \$this->notifier->statusChanged(\$actor, \$after, \$to);" : '',
]);

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Application;

__USES__

/**
 * {{label}} use cases shared by the Admin UI, Web UI and REST API.
 *
 * Security (server-side, default deny):
 *  - every operation asserts its RBAC permission ({{code}}.view|create|update|delete),
 *  - tenant isolation: without `tenants.cross` only the actor's tenant is visible; foreign ids → 404 (IDOR),
 *  - updates are optimistic-locked (version) → 409 stale_version.
 * Each change writes the row, the audit record__OUTBOX_DOC__ in ONE transaction.
 */
final class {{Entity}}Service
{
    public const REPORT_JOB = '{{code}}.report.generate';

    public function __construct(
__CTOR__
    ) {}

    public function search(User $actor, array $filters, int $page, int $perPage, ?string $sort): Page
    {
        $this->access->assert($actor->id, '{{code}}.view');
        return $this->repository->search($filters, $page, $perPage, $sort, $this->tenantRestriction($actor));
    }

    public function get(User $actor, string $id): {{Entity}}
    {
        $this->access->assert($actor->id, '{{code}}.view');
        return $this->load($actor, $id);
    }

    /** @param array<string,mixed> $input */
    public function create(User $actor, array $input): {{Entity}}
    {
        $this->access->assert($actor->id, '{{code}}.create');
        $tenantId = $this->resolveTenant($actor, $input);__RESOLVE_CREATE__
        $entity = {{Entity}}::create(Uuid::v7(), $tenantId, $input, $actor->id);
        $this->db->transaction(function () use ($entity, $actor): void {
            $this->repository->insert($entity);
            $this->audit->record('{{entity_snake}}.created', '{{entity_snake}}', $entity->id, null, $entity->toArray(), category: 'data');
            $this->publish($entity);__WORKFLOW_START____NOTIFY_CREATE__
        });
        return $this->repository->find($entity->id) ?? $entity;
    }

    /** @param array<string,mixed> $input */
    public function update(User $actor, string $id, array $input, int $expectedVersion): {{Entity}}
    {
        $this->access->assert($actor->id, '{{code}}.update');
        return $this->db->transaction(function () use ($actor, $id, $input, $expectedVersion): {{Entity}} {
            $entity = $this->loadForUpdate($actor, $id);
            $this->assertVersion($entity, $expectedVersion);
            $before = $entity->toArray();__RESOLVE_UPDATE__
            $changes = $entity->update($input);
            if ($changes === []) {
                return $entity;
            }
            $this->repository->update($entity, $expectedVersion);
            $after = $this->repository->find($entity->id) ?? $entity;
            $this->audit->record('{{entity_snake}}.updated', '{{entity_snake}}', $entity->id, $before, $after->toArray(), ['fields' => array_keys($changes)]);
            $this->publish($entity);
            return $after;
        });
    }
__STATUS_METHODS__
    /** Soft delete (permission {{code}}.delete). */
    public function delete(User $actor, string $id, ?int $expectedVersion = null): void
    {
        $this->access->assert($actor->id, '{{code}}.delete');
        $this->db->transaction(function () use ($actor, $id, $expectedVersion): void {
            $entity = $this->loadForUpdate($actor, $id);
            $this->assertVersion($entity, $expectedVersion);
            $before = $entity->toArray();
            $version = $entity->version();
            $entity->markDeleted();
            $this->repository->update($entity, $version);
            $this->audit->record('{{entity_snake}}.deleted', '{{entity_snake}}', $entity->id, $before, null, severity: 'notice');
            $this->publish($entity);
        });
    }

    /**
     * Timeline: the Kafka read model when populated, otherwise the audit trail.
     *
     * @return array{source:string, items:list<array<string,mixed>>}
     */
    public function activity(User $actor, string $id): array
    {
        $entity = $this->get($actor, $id);
__ACTIVITY__
        $items = array_map(static fn(array $r) => [
            'event_type' => $r['action'],
            'summary' => $r['action'],
            'actor_id' => $r['actor_id'],
            'occurred_at' => $r['occurred_at'],
        ], $this->repository->auditTrail($entity->id));
        return ['source' => 'audit', 'items' => $items];
    }
__REPORT_METHODS__
    public function tenantRestriction(User $actor): ?string
    {
        if ($this->access->can($actor->id, 'tenants.cross')) {
            return null;
        }
        return $actor->tenantId ?? '00000000-0000-0000-0000-000000000000';
    }

    // ------------------------------------------------------------------ internals
__PUBLISH__

    private function load(User $actor, string $id): {{Entity}}
    {
        $entity = $this->repository->find($id);
        return $this->assertVisible($actor, $entity);
    }

    private function loadForUpdate(User $actor, string $id): {{Entity}}
    {
        $entity = $this->repository->findForUpdate($id);
        return $this->assertVisible($actor, $entity);
    }

    private function assertVisible(User $actor, ?{{Entity}} $entity): {{Entity}}
    {
        $restriction = $this->tenantRestriction($actor);
        if ($entity === null || ($restriction !== null && $entity->tenantId !== $restriction)) {
            // IDOR protection: records of other tenants are reported as non-existent
            throw new NotFoundException('{{entityLabel}} not found.');
        }
        return $entity;
    }

    private function assertVersion({{Entity}} $entity, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion !== $entity->version()) {
            throw new ConflictException('The {{entityLabelLower}} was modified by someone else. Reload and try again.', 'stale_version');
        }
    }

    private function resolveTenant(User $actor, array $input): string
    {
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            if ($actor->tenantId === null) {
                throw ValidationException::single('tenant_id', 'Your account is not assigned to a tenant.');
            }
            return $restriction;
        }
        $requested = isset($input['tenant_id']) && is_string($input['tenant_id']) && $input['tenant_id'] !== '' ? strtolower($input['tenant_id']) : null;
        $tenantId = $requested ?? $actor->tenantId
            ?? ((new Query($this->db))->select('id')->from('tenants')->where(['code' => 'default'])->scalar() ?: null);
        if ($tenantId === null || !Uuid::isValid((string) $tenantId)
            || !(new Query($this->db))->from('tenants')->where(['id' => $tenantId, 'status' => 'active'])->exists()) {
            throw ValidationException::single('tenant_id', 'Unknown or inactive tenant.');
        }
        return (string) $tenantId;
    }
__RESOLVE_USERS__}

PHP, [
    '__USES__' => implode("\n", $uses),
    '__CTOR__' => implode("\n", $ctor),
    '__OUTBOX_DOC__' => $event ? ' and the outbox event (Kafka topic {{topic}})' : '',
    '__RESOLVE_CREATE__' => $users !== [] ? "\n        \$input = \$this->resolveUsers(\$actor, \$tenantId, \$input, true);" : '',
    '__RESOLVE_UPDATE__' => $users !== [] ? "\n            \$input = \$this->resolveUsers(\$actor, \$entity->tenantId, \$input, false);" : '',
    '__WORKFLOW_START__' => $workflow ? "\n            \$this->workflow->start(\$entity, \$actor->id);" : '',
    '__WORKFLOW_MIRROR__' => $workflow ? "\n            \$this->workflow->mirror(\$after, \$from, \$to, \$actor->id, \$comment);" : '',
    '__NOTIFY_STATUS__' => $notify && $status ? "\n            \$this->notifier->statusChanged(\$actor, \$after, \$to);" : '',
    '__NOTIFY_CREATE__' => $notify && !$status ? "\n            \$this->notifier->created(\$actor, \$entity);" : '',
    '__STATUS_METHODS__' => $statusMethods,
    '__REPORT_METHODS__' => $reportMethods,
    '__ACTIVITY__' => $activityBody,
    '__PUBLISH__' => $publish,
    '__RESOLVE_USERS__' => $userResolve,
]);
