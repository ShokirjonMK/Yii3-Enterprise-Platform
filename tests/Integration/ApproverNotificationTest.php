<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Queue\Worker;
use App\Platform\Workflow\ApprovalService;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

final class ApproverNotificationTest extends IntegrationTestCase
{
    public function testApproversAreNotifiedExceptRequester(): void
    {
        $requester = Fixtures::user(['business_admin']);   // business_admin holds approvals.decide
        $approver = Fixtures::user(['business_admin']);
        $outsider = Fixtures::user(['user']);

        $id = $this->get(ApprovalService::class)->request($requester['id'], 'test.op', 'Approve test op', 'approvals.decide');
        $job = (new Query($this->db))->from('jobs')->where(['idempotency_key' => 'approval-notify:' . $id])->one();
        self::assertNotNull($job, 'notification job enqueued in the same transaction');

        $this->db->createCommand("UPDATE jobs SET status = 'cancelled' WHERE queue = 'notifications' AND status = 'pending' AND id <> :id", [':id' => $job['id']])->execute();
        $this->get(Worker::class)->run(['notifications'], 0.1, 5, 512, true);

        $notified = (new Query($this->db))->select('user_id')->from('notifications')
            ->where(['template_code' => 'approval.requested', 'channel' => 'inapp'])->andWhere(['>=', 'created_at', $job['created_at']])->column();
        self::assertContains($approver['id'], $notified);
        self::assertNotContains($requester['id'], $notified, 'four-eyes: requester is not an approver');
        self::assertNotContains($outsider['id'], $notified);
    }
}
