<?php

declare(strict_types=1);

namespace App\Platform\Workflow;

use App\Platform\Access\AccessService;
use App\Platform\Notifications\Notifier;
use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use App\Platform\Queue\PermanentJobFailure;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Rbac\ItemsStorageInterface;

/**
 * Notifies every user who may decide an approval request (holds approver_permission through any role,
 * is active, belongs to the request's tenant or has tenants.cross) — except the requester (four-eyes).
 * Enqueued transactionally by ApprovalService::request() and WorkflowEngine approval-gated transitions.
 */
final class NotifyApproversJob implements JobHandlerInterface
{
    public const TYPE = 'platform.approvals.notify';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ItemsStorageInterface $items,
        private readonly AccessService $access,
        private readonly Notifier $notifier,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $request = (new Query($this->db))->select(['r.*', 'u.full_name', 'u.username'])->from(['r' => 'approval_requests'])
            ->leftJoin(['u' => 'users'], 'u.id = r.requested_by')
            ->where(['r.id' => (string) ($payload['request_id'] ?? '')])->one();
        if ($request === null) {
            throw new PermanentJobFailure('Approval request not found.');
        }
        if ($request['status'] !== 'pending') {
            return;
        }
        foreach ($this->approverIds($request) as $userId) {
            $this->notifier->notify($userId, 'approval.requested', [
                'title' => (string) $request['title'],
                'requester' => (string) ($request['full_name'] ?: $request['username']),
            ], ['inapp', 'email'], 'approvals');
        }
    }

    /** @return list<string> */
    public function approverIds(array $request): array
    {
        $roles = array_keys($this->items->getParents((string) $request['approver_permission']));
        if ($roles === []) {
            return [];
        }
        $candidates = (new Query($this->db))->select(['u.id', 'u.tenant_id'])->distinct()
            ->from(['a' => 'yii_rbac_assignment'])
            ->innerJoin(['u' => 'users'], 'u.id::text = a.user_id')
            ->where(['a.item_name' => $roles, 'u.status' => 'active', 'u.deleted_at' => null])
            ->andWhere(['<>', 'u.id', $request['requested_by']])
            ->all();
        $out = [];
        foreach ($candidates as $c) {
            if ($request['tenant_id'] !== null && $c['tenant_id'] !== $request['tenant_id'] && !$this->access->can((string) $c['id'], 'tenants.cross')) {
                continue;
            }
            $out[] = (string) $c['id'];
        }
        return $out;
    }
}
