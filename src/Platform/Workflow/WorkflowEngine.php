<?php

declare(strict_types=1);

namespace App\Platform\Workflow;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Sql;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * State machine engine over JSON definitions ({@see WorkflowDefinition}).
 *
 * Usage from a business module (e.g. Projects):
 *
 *   $instance = $engine->start('project.lifecycle', 'project', $projectId, $actorId, $tenantId);
 *   $engine->availableTransitions($instance, $actorId);            // [{name, label, to, requires_approval, …}]
 *   $result = $engine->apply($instance['id'], 'activate', $actorId, $instance['version'], 'Kick-off done');
 *   // $result['status'] === 'applied' | 'pending_approval' (approval-gated transition → approval_requests row)
 *
 * Guarantees: permission check per transition (AccessService), optimistic locking (workflow_instances.version),
 * full history (workflow_transitions), audit record per change, tenant isolation (tenants.cross bypass).
 */
final class WorkflowEngine
{
    /** @var array<string, WorkflowDefinition> */
    private array $parsed = [];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------------------------------ definitions

    public function workflowsQuery(): Query
    {
        return (new Query($this->db))->from('workflows');
    }

    public function findWorkflow(string $id): array
    {
        $row = Uuid::isValid($id) ? $this->workflowsQuery()->where(['id' => $id])->one() : null;
        return $row ?? throw new NotFoundException('Workflow not found.');
    }

    public function findByCode(string $code): array
    {
        return $this->workflowsQuery()->where(['code' => $code])->one() ?? throw new NotFoundException("Workflow \"$code\" not found.");
    }

    public function definition(array $workflow): WorkflowDefinition
    {
        $key = $workflow['id'] . ':' . $workflow['version'];
        return $this->parsed[$key] ??= WorkflowDefinition::parse(Sql::json($workflow['definition']));
    }

    /** @param array<string,mixed> $input code, name, entity_type, definition (JSON string), enabled */
    public function createWorkflow(string $actorId, array $input): string
    {
        $this->access->assert($actorId, 'workflow.manage');
        $data = $this->validateWorkflow($input, true);
        if ($this->workflowsQuery()->where(['code' => $data['code']])->exists()) {
            throw ValidationException::single('code', 'This code is already used.');
        }
        $id = Uuid::v7();
        $this->db->transaction(function () use ($id, $data, $actorId): void {
            $this->db->createCommand()->insert('workflows', ['id' => $id] + $data)->execute();
            $this->audit->record('workflow.created', 'workflow', $id, null, ['code' => $data['code'], 'entity_type' => $data['entity_type']], category: 'config', severity: 'notice', actorId: $actorId);
        });
        return $id;
    }

    /** @param array<string,mixed> $input */
    public function updateWorkflow(string $actorId, string $id, array $input, int $version): void
    {
        $this->access->assert($actorId, 'workflow.manage');
        $row = $this->findWorkflow($id);
        $data = $this->validateWorkflow($input + ['code' => $row['code'], 'entity_type' => $row['entity_type']], false);
        unset($data['code'], $data['entity_type']);
        $new = WorkflowDefinition::parse($data['definition']);
        // states that live instances are in must survive the change
        $used = (new Query($this->db))->select('state')->distinct()->from('workflow_instances')->where(['workflow_id' => $id])->column();
        $missing = array_values(array_diff($used, $new->states));
        if ($missing !== []) {
            throw ValidationException::single('definition', 'States in use by existing instances cannot be removed: ' . implode(', ', $missing));
        }
        $this->db->transaction(function () use ($row, $data, $actorId, $version): void {
            $n = $this->db->createCommand()->update('workflows', $data + ['version' => new Expression('version + 1'), 'updated_at' => new Expression('now()')],
                ['id' => $row['id'], 'version' => $version])->execute();
            if ($n === 0) {
                throw new ConflictException('The workflow was modified concurrently. Reload and try again.', 'stale_version');
            }
            $this->audit->record('workflow.updated', 'workflow', $row['id'], ['version' => (int) $row['version'], 'definition' => Sql::json($row['definition'])],
                ['version' => $version + 1, 'definition' => json_decode((string) $data['definition'], true), 'enabled' => $data['enabled']], category: 'config', severity: 'notice', actorId: $actorId);
        });
    }

    public function deleteWorkflow(string $actorId, string $id): void
    {
        $this->access->assert($actorId, 'workflow.manage');
        $row = $this->findWorkflow($id);
        if ((new Query($this->db))->from('workflow_instances')->where(['workflow_id' => $id])->exists()) {
            throw new ConflictException('The workflow has instances; disable it instead of deleting.', 'in_use');
        }
        $this->db->transaction(function () use ($row, $actorId): void {
            $this->db->createCommand()->delete('workflows', ['id' => $row['id']])->execute();
            $this->audit->record('workflow.deleted', 'workflow', $row['id'], ['code' => $row['code']], null, category: 'config', severity: 'warning', actorId: $actorId);
        });
    }

    // ------------------------------------------------------------------------------------------ instances

    public function instancesQuery(): Query
    {
        return (new Query($this->db))->select(['i.*', 'w.code AS workflow_code', 'w.name AS workflow_name'])
            ->from(['i' => 'workflow_instances'])->innerJoin(['w' => 'workflows'], 'w.id = i.workflow_id');
    }

    public function instance(string $id, ?string $actorId = null): array
    {
        $row = Uuid::isValid($id) ? $this->instancesQuery()->where(['i.id' => $id])->one() : null;
        if ($row === null) {
            throw new NotFoundException('Workflow instance not found.');
        }
        if ($actorId !== null) {
            $this->assertTenant($row, $actorId);
        }
        return $row;
    }

    public function instanceFor(string $workflowCode, string $entityType, string $entityId): ?array
    {
        return $this->instancesQuery()->where(['w.code' => $workflowCode, 'i.entity_type' => $entityType, 'i.entity_id' => $entityId])->one();
    }

    /** @param array<string,mixed> $data */
    public function start(string $workflowCode, string $entityType, string $entityId, ?string $actorId = null, ?string $tenantId = null, array $data = []): array
    {
        $wf = $this->findByCode($workflowCode);
        if (!Sql::bool($wf['enabled'])) {
            throw new ConflictException("Workflow \"$workflowCode\" is disabled.", 'workflow_disabled');
        }
        if ($wf['entity_type'] !== $entityType) {
            throw ValidationException::single('entity_type', "Workflow \"$workflowCode\" is defined for \"{$wf['entity_type']}\", not \"$entityType\".");
        }
        if ($entityId === '' || strlen($entityId) > 96) {
            throw ValidationException::single('entity_id', 'Invalid entity id.');
        }
        $def = $this->definition($wf);
        $id = Uuid::v7();
        $this->db->transaction(function () use ($id, $wf, $entityType, $entityId, $actorId, $tenantId, $data, $def): void {
            $inserted = $this->db->createCommand(
                'INSERT INTO workflow_instances (id, workflow_id, entity_type, entity_id, tenant_id, state, data, started_by)
                 VALUES (:id, :w, :et, :eid, :t, :s, CAST(:d AS jsonb), :u) ON CONFLICT ON CONSTRAINT workflow_instances_entity_uq DO NOTHING RETURNING id',
                [':id' => $id, ':w' => $wf['id'], ':et' => $entityType, ':eid' => $entityId, ':t' => $tenantId, ':s' => $def->initial,
                 ':d' => json_encode($data === [] ? new \stdClass() : $data, JSON_UNESCAPED_UNICODE), ':u' => $actorId],
            )->queryScalar();
            if ($inserted === false || $inserted === null) {
                throw new ConflictException('A workflow instance already exists for this entity.', 'instance_exists');
            }
            $this->db->createCommand()->insert('workflow_transitions', [
                'instance_id' => $id, 'transition' => 'start', 'from_state' => '', 'to_state' => $def->initial, 'actor_id' => $actorId,
            ])->execute();
            $this->audit->record('workflow.started', $entityType, $entityId, null, ['state' => $def->initial], ['workflow' => $wf['code'], 'instance_id' => $id], actorId: $actorId);
        });
        return $this->instance($id);
    }

    /**
     * Transitions the user may trigger now (state + permission).
     *
     * @return list<array{name:string, label:string, to:string, to_label:string, requires_approval:bool, pending_approval_id:?string}>
     */
    public function availableTransitions(array $instance, string $userId): array
    {
        $wf = $this->findWorkflow((string) $instance['workflow_id']);
        $def = $this->definition($wf);
        $pending = (new Query($this->db))->select(['transition', 'id'])->from('approval_requests')
            ->where(['instance_id' => $instance['id'], 'status' => 'pending'])->all();
        $pendingBy = array_column($pending, 'id', 'transition');
        $out = [];
        foreach ($def->transitionsFrom((string) $instance['state']) as $name) {
            $t = $def->transitions[$name];
            if ($t['permission'] !== null && !$this->access->can($userId, $t['permission'])) {
                continue;
            }
            $out[] = [
                'name' => $name, 'label' => $t['label'], 'to' => $t['to'], 'to_label' => $def->label($t['to']),
                'requires_approval' => $t['approval'] !== null, 'pending_approval_id' => $pendingBy[$name] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array{status:'applied'|'pending_approval', instance:array<string,mixed>, approval_id:?string}
     */
    public function apply(string $instanceId, string $transition, string $actorId, ?int $expectedVersion = null, string $comment = ''): array
    {
        $instance = $this->instance($instanceId, $actorId);
        $wf = $this->findWorkflow((string) $instance['workflow_id']);
        if (!Sql::bool($wf['enabled'])) {
            throw new ConflictException('The workflow is disabled.', 'workflow_disabled');
        }
        $def = $this->definition($wf);
        $t = $def->transitions[$transition] ?? throw new NotFoundException("Unknown transition \"$transition\".");
        if (!in_array($instance['state'], $t['from'], true)) {
            throw new ConflictException("Transition \"$transition\" is not allowed from state \"{$instance['state']}\".", 'invalid_transition');
        }
        if ($t['permission'] !== null) {
            $this->access->assert($actorId, $t['permission']);
        }
        $version = $expectedVersion ?? (int) $instance['version'];
        if ($version !== (int) $instance['version']) {
            throw new ConflictException('The item was changed by someone else. Reload and try again.', 'stale_version');
        }
        $comment = mb_substr(trim($comment), 0, 2000);

        if ($t['approval'] !== null) {
            $exists = (new Query($this->db))->from('approval_requests')->where(['instance_id' => $instanceId, 'transition' => $transition, 'status' => 'pending'])->exists();
            if ($exists) {
                throw new ConflictException('An approval request for this transition is already pending.', 'approval_pending');
            }
            $approvalId = Uuid::v7();
            $this->db->transaction(function () use ($approvalId, $instance, $transition, $t, $def, $actorId, $comment, $wf): void {
                $expires = $t['approval']['expires_hours'] !== null ? (new DateTimeImmutable('+' . $t['approval']['expires_hours'] . ' hours'))->format(DATE_ATOM) : null;
                $this->db->createCommand()->insert('approval_requests', [
                    'id' => $approvalId,
                    'tenant_id' => $instance['tenant_id'],
                    'type' => 'workflow.transition',
                    'title' => mb_substr(sprintf('%s: %s %s → %s', $wf['name'], $instance['entity_type'], $instance['entity_id'], $def->label($t['to'])), 0, 255),
                    'entity_type' => $instance['entity_type'],
                    'entity_id' => $instance['entity_id'],
                    'instance_id' => $instance['id'],
                    'transition' => $transition,
                    'payload' => json_encode(['comment' => $comment, 'from' => $instance['state'], 'to' => $t['to'], 'workflow' => $wf['code'], 'version' => (int) $instance['version']], JSON_UNESCAPED_UNICODE),
                    'approver_permission' => $t['approval']['permission'],
                    'required_approvals' => $t['approval']['required_approvals'],
                    'requested_by' => $actorId,
                    'expires_at' => $expires,
                ])->execute();
                $this->audit->record('approval.requested', 'approval_request', $approvalId, null,
                    ['transition' => $transition, 'instance_id' => $instance['id'], 'required_approvals' => $t['approval']['required_approvals']],
                    ['workflow' => $wf['code'], 'entity' => $instance['entity_type'] . ':' . $instance['entity_id']], actorId: $actorId);
            });
            return ['status' => 'pending_approval', 'instance' => $this->instance($instanceId), 'approval_id' => $approvalId];
        }

        $this->db->transaction(fn() => $this->transitionInTransaction($instance, $transition, $t['to'], $version, $actorId, $comment, null));
        return ['status' => 'applied', 'instance' => $this->instance($instanceId), 'approval_id' => null];
    }

    /**
     * Applies the transition of an approved request. MUST be called inside the caller's transaction
     * ({@see ApprovalService::decide()}). Returns false when the instance moved on (the request is then cancelled).
     */
    public function applyApproved(array $request, string $approverId): bool
    {
        $instance = $this->instancesQuery()->where(['i.id' => $request['instance_id']])->one();
        if ($instance === null) {
            return false;
        }
        $wf = $this->findWorkflow((string) $instance['workflow_id']);
        $def = $this->definition($wf);
        $t = $def->transitions[(string) $request['transition']] ?? null;
        if ($t === null || !in_array($instance['state'], $t['from'], true)) {
            return false;
        }
        $this->transitionInTransaction($instance, (string) $request['transition'], $t['to'], (int) $instance['version'], $approverId,
            'Approved (request ' . $request['id'] . ')' . (($c = (string) (Sql::json($request['payload'])['comment'] ?? '')) !== '' ? ': ' . $c : ''), (string) $request['id']);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function history(string $instanceId): array
    {
        return (new Query($this->db))->select(['t.*', 'u.username AS actor_name'])->from(['t' => 'workflow_transitions'])
            ->leftJoin(['u' => 'users'], 'u.id = t.actor_id')
            ->where(['t.instance_id' => $instanceId])->orderBy(['t.id' => SORT_ASC])->all();
    }

    public function assertTenant(array $row, string $actorId): void
    {
        if ($row['tenant_id'] === null || $this->access->can($actorId, 'tenants.cross')) {
            return;
        }
        $tenant = (new Query($this->db))->select('tenant_id')->from('users')->where(['id' => $actorId])->scalar();
        if ($tenant !== $row['tenant_id']) {
            throw new NotFoundException('Workflow instance not found.');
        }
    }

    private function transitionInTransaction(array $instance, string $transition, string $to, int $version, string $actorId, string $comment, ?string $approvalId): void
    {
        $n = $this->db->createCommand()->update('workflow_instances',
            ['state' => $to, 'version' => new Expression('version + 1'), 'updated_at' => new Expression('now()')],
            ['id' => $instance['id'], 'version' => $version])->execute();
        if ($n === 0) {
            throw new ConflictException('The item was changed by someone else. Reload and try again.', 'stale_version');
        }
        $this->db->createCommand()->insert('workflow_transitions', [
            'instance_id' => $instance['id'], 'transition' => $transition, 'from_state' => $instance['state'], 'to_state' => $to,
            'actor_id' => $actorId, 'comment' => $comment === '' ? null : $comment,
        ])->execute();
        // other pending approvals were requested for the previous state — they are obsolete now
        $cancel = ['and', ['instance_id' => $instance['id'], 'status' => 'pending']];
        if ($approvalId !== null) {
            $cancel[] = ['<>', 'id', $approvalId];
        }
        $this->db->createCommand()->update('approval_requests', ['status' => 'cancelled', 'decided_at' => new Expression('now()')], $cancel)->execute();
        $this->audit->record('workflow.transition', (string) $instance['entity_type'], (string) $instance['entity_id'],
            ['state' => $instance['state']], ['state' => $to],
            ['workflow' => $instance['workflow_code'] ?? null, 'instance_id' => $instance['id'], 'transition' => $transition, 'approval_id' => $approvalId, 'comment' => $comment],
            actorId: $actorId);
    }

    /** @return array<string,mixed> */
    private function validateWorkflow(array $in, bool $creating): array
    {
        $e = [];
        $code = trim((string) ($in['code'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $code) !== 1) {
            $e['code'][] = '2–96 characters: lowercase letters, digits, ".", "-", "_".';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            $e['name'][] = 'Name is required (max 255 characters).';
        }
        $entity = trim((string) ($in['entity_type'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $entity) !== 1) {
            $e['entity_type'][] = 'Entity type: lowercase identifier, e.g. "project".';
        }
        $raw = $in['definition'] ?? '';
        $report = WorkflowDefinition::analyse(is_string($raw) ? $raw : json_encode($raw));
        if ($report['errors'] !== []) {
            $e['definition'] = $report['errors'];
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return [
            'code' => $code, 'name' => $name, 'entity_type' => $entity,
            'definition' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'enabled' => filter_var($in['enabled'] ?? ($creating ? true : false), FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
