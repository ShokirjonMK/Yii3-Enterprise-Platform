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
 * Approval requests with four-eyes control:
 *  - the requester can never approve/reject their own request;
 *  - every approver decides at most once (UNIQUE (request_id, approver_id));
 *  - approvers need `approvals.decide` AND the request's approver_permission;
 *  - quorum: `required_approvals` approvals → approved; a single rejection → rejected (closed);
 *  - expired requests (expires_at) cannot be decided and are marked "expired";
 *  - an approved workflow request applies its pending transition atomically with the final decision.
 */
final class ApprovalService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $engine,
    ) {}

    public function query(): Query
    {
        return (new Query($this->db))->select(['r.*', 'u.username AS requested_by_name',
            new Expression('(SELECT count(*) FROM approval_decisions d WHERE d.request_id = r.id AND d.decision = \'approve\') AS approvals')])
            ->from(['r' => 'approval_requests'])->leftJoin(['u' => 'users'], 'u.id = r.requested_by');
    }

    public function find(string $id, ?string $actorId = null): array
    {
        $row = Uuid::isValid($id) ? $this->query()->where(['r.id' => $id])->one() : null;
        if ($row === null) {
            throw new NotFoundException('Approval request not found.');
        }
        if ($actorId !== null) {
            $this->engine->assertTenant($row, $actorId);
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function decisions(string $requestId): array
    {
        return (new Query($this->db))->select(['d.*', 'u.username AS approver_name'])->from(['d' => 'approval_decisions'])
            ->leftJoin(['u' => 'users'], 'u.id = d.approver_id')->where(['d.request_id' => $requestId])->orderBy(['d.id' => SORT_ASC])->all();
    }

    /**
     * Generic (non-workflow) approval request, e.g. for a module operation.
     *
     * @param array<string,mixed> $payload
     */
    public function request(string $requesterId, string $type, string $title, string $approverPermission, int $requiredApprovals = 1, ?string $entityType = null, ?string $entityId = null, array $payload = [], ?DateTimeImmutable $expiresAt = null, ?string $tenantId = null): string
    {
        if (preg_match('/^[a-z0-9_.:-]{2,64}$/', $type) !== 1) {
            throw ValidationException::single('type', 'Invalid approval type.');
        }
        if ($requiredApprovals < 1 || $requiredApprovals > 10) {
            throw ValidationException::single('required_approvals', 'Must be 1–10.');
        }
        $id = Uuid::v7();
        $this->db->transaction(function () use ($id, $requesterId, $type, $title, $approverPermission, $requiredApprovals, $entityType, $entityId, $payload, $expiresAt, $tenantId): void {
            $this->db->createCommand()->insert('approval_requests', [
                'id' => $id, 'tenant_id' => $tenantId, 'type' => $type, 'title' => mb_substr($title, 0, 255),
                'entity_type' => $entityType, 'entity_id' => $entityId, 'payload' => json_encode($payload === [] ? new \stdClass() : $payload, JSON_UNESCAPED_UNICODE),
                'approver_permission' => $approverPermission, 'required_approvals' => $requiredApprovals,
                'requested_by' => $requesterId, 'expires_at' => $expiresAt?->format(DATE_ATOM),
            ])->execute();
            $this->audit->record('approval.requested', 'approval_request', $id, null, ['type' => $type, 'required_approvals' => $requiredApprovals], actorId: $requesterId);
        });
        return $id;
    }

    /**
     * @param 'approve'|'reject' $decision
     * @return string resulting request status (pending | approved | rejected | cancelled)
     */
    public function decide(string $requestId, string $approverId, string $decision, string $comment = ''): string
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::single('decision', 'Decision must be approve or reject.');
        }
        $comment = mb_substr(trim($comment), 0, 2000);
        if ($decision === 'reject' && $comment === '') {
            throw ValidationException::single('comment', 'A comment is required when rejecting.');
        }
        $this->access->assert($approverId, 'approvals.decide');
        $current = $this->find($requestId, $approverId); // tenant isolation (IDOR → 404)
        if ($current['requested_by'] === $approverId) {
            $this->audit->security('approval.four_eyes_violation', 'denied', ['request_id' => $requestId], 'warning', $approverId);
            throw new AccessDeniedException('Four-eyes principle: you cannot decide on your own request.');
        }

        $expired = false;
        $status = $this->db->transaction(function () use ($requestId, $approverId, $decision, $comment, &$expired): string {
            $r = $this->db->createCommand('SELECT * FROM approval_requests WHERE id = :id FOR UPDATE', [':id' => $requestId])->queryOne();
            if ($r === null) {
                throw new NotFoundException('Approval request not found.');
            }
            if ($r['status'] !== 'pending') {
                throw new ConflictException("The request is already {$r['status']}.", 'already_decided');
            }
            if ($r['expires_at'] !== null && new DateTimeImmutable((string) $r['expires_at']) <= new DateTimeImmutable()) {
                $this->db->createCommand()->update('approval_requests', ['status' => 'expired', 'decided_at' => new Expression('now()')], ['id' => $requestId])->execute();
                $this->audit->record('approval.expired', 'approval_request', $requestId, ['status' => 'pending'], ['status' => 'expired'], actorId: $approverId);
                $expired = true;
                return 'expired';
            }
            if ($r['requested_by'] === $approverId) {
                throw new AccessDeniedException('Four-eyes principle: you cannot decide on your own request.');
            }
            $this->access->assert($approverId, (string) $r['approver_permission']);
            $inserted = $this->db->createCommand(
                'INSERT INTO approval_decisions (request_id, approver_id, decision, comment) VALUES (:r, :a, :d, :c)
                 ON CONFLICT ON CONSTRAINT approval_decisions_once DO NOTHING RETURNING id',
                [':r' => $requestId, ':a' => $approverId, ':d' => $decision, ':c' => $comment === '' ? null : $comment],
            )->queryScalar();
            if ($inserted === false || $inserted === null) {
                throw new ConflictException('You have already decided on this request.', 'already_decided');
            }
            $this->audit->record('approval.decision', 'approval_request', $requestId, null, ['decision' => $decision], ['comment' => $comment], actorId: $approverId);

            if ($decision === 'reject') {
                $this->close($requestId, 'rejected', $approverId);
                return 'rejected';
            }
            $approvals = (int) $this->db->createCommand("SELECT count(*) FROM approval_decisions WHERE request_id = :r AND decision = 'approve'", [':r' => $requestId])->queryScalar();
            if ($approvals < (int) $r['required_approvals']) {
                return 'pending';
            }
            if ($r['instance_id'] !== null && $r['transition'] !== null && !$this->engine->applyApproved($r, $approverId)) {
                $this->close($requestId, 'cancelled', $approverId, 'instance state changed');
                return 'cancelled';
            }
            $this->close($requestId, 'approved', $approverId);
            return 'approved';
        });
        if ($expired) {
            throw new ConflictException('The approval request has expired.', 'expired');
        }
        return $status;
    }

    /** The requester withdraws a pending request. */
    public function cancel(string $requestId, string $actorId): void
    {
        $r = $this->find($requestId, $actorId);
        if ($r['requested_by'] !== $actorId && !$this->access->can($actorId, 'workflow.manage')) {
            throw new AccessDeniedException('Only the requester or a workflow manager can cancel the request.');
        }
        $this->db->transaction(function () use ($requestId, $actorId): void {
            $n = $this->db->createCommand()->update('approval_requests', ['status' => 'cancelled', 'decided_at' => new Expression('now()')], ['id' => $requestId, 'status' => 'pending'])->execute();
            if ($n === 0) {
                throw new ConflictException('Only pending requests can be cancelled.', 'not_pending');
            }
            $this->audit->record('approval.cancelled', 'approval_request', $requestId, ['status' => 'pending'], ['status' => 'cancelled'], actorId: $actorId);
        });
    }

    /**
     * Pending requests the user can decide: has the approver permission, is not the requester, has not decided yet,
     * request not expired, same tenant (unless tenants.cross).
     *
     * @return list<array<string,mixed>>
     */
    public function pendingFor(string $userId, int $limit = 200): array
    {
        if (!$this->access->can($userId, 'approvals.decide')) {
            return [];
        }
        $q = $this->query()->where(['r.status' => 'pending'])
            ->andWhere(['<>', 'r.requested_by', $userId])
            ->andWhere(['or', ['r.expires_at' => null], ['>', 'r.expires_at', new Expression('now()')]])
            ->andWhere(new Expression('NOT EXISTS (SELECT 1 FROM approval_decisions d WHERE d.request_id = r.id AND d.approver_id = :me)', [':me' => $userId]))
            ->orderBy(['r.created_at' => SORT_ASC])->limit($limit);
        if (!$this->access->can($userId, 'tenants.cross')) {
            $tenant = (new Query($this->db))->select('tenant_id')->from('users')->where(['id' => $userId])->scalar();
            $q->andWhere(['or', ['r.tenant_id' => null], ['r.tenant_id' => $tenant ?: null]]);
        }
        $can = [];
        return array_values(array_filter($q->all(), function (array $r) use ($userId, &$can): bool {
            return $can[$r['approver_permission']] ??= $this->access->can($userId, (string) $r['approver_permission']);
        }));
    }

    /** Marks overdue pending requests as expired (scheduled job). @return int */
    public function expireOverdue(): int
    {
        $ids = $this->db->createCommand(
            "UPDATE approval_requests SET status = 'expired', decided_at = now() WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at <= now() RETURNING id",
        )->queryColumn();
        foreach ($ids as $id) {
            $this->audit->record('approval.expired', 'approval_request', (string) $id, ['status' => 'pending'], ['status' => 'expired']);
        }
        return count($ids);
    }

    private function close(string $id, string $status, string $actorId, string $note = ''): void
    {
        $this->db->createCommand()->update('approval_requests', ['status' => $status, 'decided_at' => new Expression('now()')], ['id' => $id])->execute();
        $this->audit->record('approval.' . $status, 'approval_request', $id, ['status' => 'pending'], ['status' => $status], $note === '' ? [] : ['note' => $note], actorId: $actorId);
    }
}
