<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Modules\Projects\Application\ProjectService;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Infrastructure\ProjectActivityProjector;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Messaging\Kafka\KafkaConsumerRunner;
use App\Platform\Queue\JobQueue;
use App\Platform\Queue\Worker;
use App\Platform\Storage\ObjectStorage;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

final class ProjectsServiceTest extends IntegrationTestCase
{
    private ProjectService $service;
    private User $admin;
    private User $foreignAdmin;
    private User $member;
    private string $foreignTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->get(ProjectService::class);
        $this->foreignTenant = (string) $this->db->createCommand(
            "INSERT INTO tenants (code, name) VALUES (:c, 'Foreign test tenant') RETURNING id",
            [':c' => 't-' . bin2hex(random_bytes(4))],
        )->queryScalar();
        $this->admin = $this->user(Fixtures::user(['business_admin']));
        $this->member = $this->user(Fixtures::user(['user']));
        $this->foreignAdmin = $this->user(Fixtures::user(['business_admin'], extra: ['tenant_id' => $this->foreignTenant]));
    }

    public function testCreateWritesProjectAuditAndOutboxAtomically(): void
    {
        $p = $this->service->create($this->admin, ['code' => $this->code(), 'name' => 'Atomic project', 'tags' => ['a']]);
        self::assertSame(ProjectStatus::Draft, $p->status());
        self::assertSame($this->admin->id, $p->ownerId());
        $outbox = (new Query($this->db))->from('outbox')->where(['aggregate_id' => $p->id])->all();
        self::assertCount(1, $outbox);
        self::assertSame('projects.events', $outbox[0]['topic']);
        self::assertSame('project.created', $outbox[0]['event_type']);
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['entity_type' => 'project', 'entity_id' => $p->id, 'action' => 'project.created'])->exists());
        self::assertSame('owner', (new Query($this->db))->select('role')->from('project_members')->where(['project_id' => $p->id])->scalar());

        // Same transaction: rolling back the business transaction also removes the event
        $code = $this->code();
        $tx = $this->db->beginTransaction();
        $rolled = $this->service->create($this->admin, ['code' => $code, 'name' => 'Rolled back']);
        self::assertTrue((new Query($this->db))->from('outbox')->where(['aggregate_id' => $rolled->id])->exists());
        $tx->rollBack();
        self::assertFalse((new Query($this->db))->from('projects')->where(['id' => $rolled->id])->exists());
        self::assertFalse((new Query($this->db))->from('outbox')->where(['aggregate_id' => $rolled->id])->exists());
    }

    public function testValidationAndDuplicateCode(): void
    {
        $code = $this->code();
        $this->service->create($this->admin, ['code' => $code, 'name' => 'First']);
        try {
            $this->service->create($this->admin, ['code' => $code, 'name' => 'Second']);
            self::fail('duplicate code accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('code', $e->errors());
        }
        // the same code is fine in another tenant
        $other = $this->service->create($this->foreignAdmin, ['code' => $code, 'name' => 'Other tenant']);
        self::assertSame($this->foreignTenant, $other->tenantId);
    }

    public function testTenantIsolationReportsForeignProjectsAsNotFound(): void
    {
        $p = $this->service->create($this->admin, ['code' => $this->code(), 'name' => 'Tenant A project']);
        try {
            $this->service->get($this->foreignAdmin, $p->id);
            self::fail('foreign tenant could read the project');
        } catch (NotFoundException) {
        }
        $this->expectException(NotFoundException::class);
        $this->service->update($this->foreignAdmin, $p->id, ['name' => 'Hijack'], 1);
    }

    public function testSearchIsTenantScoped(): void
    {
        $code = $this->code();
        $this->service->create($this->foreignAdmin, ['code' => $code, 'name' => 'Hidden from tenant A']);
        $page = $this->service->search($this->admin, ['q' => $code], 1, 50, null);
        self::assertSame(0, $page->total);
        $page = $this->service->search($this->foreignAdmin, ['q' => $code], 1, 50, null);
        self::assertSame(1, $page->total);
    }

    public function testOptimisticLocking(): void
    {
        $p = $this->service->create($this->admin, ['code' => $this->code(), 'name' => 'Lock me']);
        $v2 = $this->service->update($this->admin, $p->id, ['name' => 'Locked v2'], 1);
        self::assertSame(2, $v2->version());
        try {
            $this->service->update($this->admin, $p->id, ['name' => 'Stale write'], 1);
            self::fail('stale write accepted');
        } catch (ConflictException $e) {
            self::assertSame('stale_version', $e->errorCode());
        }
        self::assertSame('Locked v2', $this->service->get($this->admin, $p->id)->name());
        self::assertSame(2, (int) (new Query($this->db))->from('outbox')->where(['aggregate_id' => $p->id])->count(), 'created + updated');
    }

    public function testPermissionsAreEnforced(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->service->create($this->member, ['code' => $this->code(), 'name' => 'Not allowed']);
    }

    public function testStatusChangeNotifiesMembersAndRejectsInvalidTransitions(): void
    {
        $p = $this->service->create($this->admin, ['code' => $this->code(), 'name' => 'Lifecycle']);
        $memberUsername = $this->member->username;
        $this->service->addMember($this->admin, $p->id, $memberUsername, 'member');

        $active = $this->service->changeStatus($this->admin, $p->id, 'active', 1);
        self::assertSame(ProjectStatus::Active, $active->status());
        $notified = (new Query($this->db))->from('notifications')
            ->where(['user_id' => $this->member->id, 'template_code' => 'project.status_changed'])->count();
        self::assertGreaterThanOrEqual(1, (int) $notified);
        self::assertTrue((new Query($this->db))->from('outbox')->where(['aggregate_id' => $p->id, 'event_type' => 'project.status_changed'])->exists());

        try {
            $this->service->archive($this->admin, $p->id);
            self::fail('active project archived');
        } catch (ConflictException $e) {
            self::assertSame('invalid_transition', $e->errorCode());
        }
        $this->service->changeStatus($this->admin, $p->id, 'completed');
        $outcome = $this->service->archive($this->admin, $p->id);
        if ($outcome->isPending()) {
            // workflow "project.lifecycle": archive needs a four-eyes approval
            self::assertSame(ProjectStatus::Completed, $outcome->project->status());
            self::assertSame($outcome->approvalId, $this->service->pendingArchiveApproval($outcome->project));
            $approvals = $this->get(\App\Platform\Workflow\ApprovalService::class);
            try {
                $approvals->decide((string) $outcome->approvalId, $this->admin->id, 'approve');
                self::fail('requester approved own request');
            } catch (AccessDeniedException) {
            }
            $approver = $this->user(Fixtures::user(['business_admin']));
            self::assertSame('approved', $approvals->decide((string) $outcome->approvalId, $approver->id, 'approve'));
        }
        $archived = $this->service->get($this->admin, $p->id);
        self::assertSame(ProjectStatus::Archived, $archived->status());
        self::assertTrue((new Query($this->db))->from('outbox')->where(['aggregate_id' => $p->id, 'event_type' => 'project.archived'])->exists());
        $this->expectException(ConflictException::class);
        $this->service->update($this->admin, $p->id, ['name' => 'Read-only'], $archived->version());
    }

    public function testReportJobRunsThroughWorker(): void
    {
        $this->service->create($this->admin, ['code' => $this->code(), 'name' => '=cmd|evil', 'budget' => '10.50']);
        $r = $this->service->requestReport($this->admin);
        self::assertSame('pending', $this->service->report($this->admin, $r['report_id'])['status']);
        // isolate the job on a private queue so the shared worker pool does not race us
        $this->db->createCommand()->update('jobs', ['queue' => 'test-projects'], ['id' => $r['job_id']])->execute();

        /** @var Worker $worker */
        $worker = $this->get(Worker::class);
        $worker->run(['test-projects'], 0.1, 1, 512, true);

        $report = $this->service->report($this->admin, $r['report_id']);
        /** @var ObjectStorage $storage */
        $storage = $this->get(ObjectStorage::class);
        if (!$storage->isConfigured()) {
            self::assertSame('failed', $report['status']);
            self::assertStringContainsString('not configured', (string) $report['error']);
            return;
        }
        self::assertSame('done', $report['status'], (string) $report['error']);
        self::assertSame('succeeded', $this->get(JobQueue::class)->find($r['job_id'])['status']);
        self::assertStringStartsWith('reports/projects/' . $this->admin->tenantId . '/', (string) $report['path']);
        $csv = $storage->filesystem()->read((string) $report['path']);
        self::assertStringContainsString('code,name,status', $csv);
        self::assertStringContainsString("'=cmd|evil", $csv, 'formula injection neutralised');
        self::assertSame(hash('sha256', $csv), $report['checksum_sha256']);

        // the foreign tenant cannot see the report
        $this->expectException(NotFoundException::class);
        $this->service->report($this->foreignAdmin, $r['report_id']);
    }

    public function testConsumerMaintainsActivityReadModelIdempotently(): void
    {
        $p = $this->service->create($this->admin, ['code' => $this->code(), 'name' => 'Read model']);
        $this->service->changeStatus($this->admin, $p->id, 'active');
        /** @var KafkaConsumerRunner $runner */
        $runner = $this->get(KafkaConsumerRunner::class);
        $handlers = require dirname(__DIR__, 2) . '/config/common/events/projects.php';
        $group = 'projects-audit';
        self::assertSame(ProjectActivityProjector::class, $handlers[$group]['handlers']['project.status_changed']);

        $rows = (new Query($this->db))->from('outbox')->where(['aggregate_id' => $p->id])->orderBy(['created_at' => SORT_ASC])->all();
        self::assertCount(2, $rows);
        foreach ([1, 2] as $_) { // second pass = redelivery, must be a no-op
            foreach ($rows as $row) {
                $msg = new \RdKafka\Message();
                $msg->payload = (string) $row['payload'];
                $msg->topic_name = 'projects.events';
                $msg->headers = json_decode((string) $row['headers'], true) ?: [];
                $runner->process($group, $handlers[$group]['handlers'], $msg);
            }
        }
        $activity = (new Query($this->db))->from('project_activity')->where(['project_id' => $p->id])->orderBy(['occurred_at' => SORT_ASC])->all();
        self::assertCount(2, $activity);
        self::assertSame(['project.created', 'project.status_changed'], array_column($activity, 'event_type'));
        self::assertSame('read_model', $this->service->activity($this->admin, $p->id)['source']);
    }

    private function user(array $fixture): User
    {
        return $this->get(UserRepository::class)->find($fixture['id']) ?? throw new \RuntimeException('fixture user missing');
    }

    private function code(): string
    {
        return 'T-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
