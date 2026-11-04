<?php

declare(strict_types=1);

namespace App\Modules\Projects\Application;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectEvent;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Infrastructure\ProjectRepository;
use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Messaging\Outbox;
use App\Platform\Notifications\Notifier;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Page;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Projects use cases shared by the Admin UI, Web UI and REST API.
 *
 * Security rules (all server-side, default deny):
 *  - every operation checks its RBAC permission (projects.view|create|update|delete|approve),
 *  - tenant isolation: without `tenants.cross` an actor only sees projects of its tenant; foreign ids → 404,
 *  - mutations require owner OR `projects.manage_all`,
 *  - activating a draft requires `projects.approve`; archiving requires `projects.delete`,
 *  - updates are optimistic-locked (version) → 409 stale_version.
 * Each state change writes audit + outbox event in the same transaction.
 */
final class ProjectService
{
    public const REPORT_JOB = 'projects.report.generate';
    public const NOTIFY_TEMPLATE = 'project.status_changed';

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly UserRepository $users,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly Outbox $outbox,
        private readonly Notifier $notifier,
        private readonly JobQueue $queue,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
        private readonly ProjectWorkflow $workflow,
    ) {}

    public function search(User $actor, array $filters, int $page, int $perPage, ?string $sort): Page
    {
        $this->access->assert($actor->id, 'projects.view');
        return $this->projects->search($filters, $page, $perPage, $sort, $this->tenantRestriction($actor));
    }

    public function get(User $actor, string $id): Project
    {
        $this->access->assert($actor->id, 'projects.view');
        return $this->finalizeApprovedArchive($this->load($actor, $id));
    }

    /** Pending archive approval request id (workflow "project.lifecycle"), if any. */
    public function pendingArchiveApproval(Project $project): ?string
    {
        return $this->workflow->pendingArchive($project);
    }

    public function archiveNeedsApproval(Project $project): bool
    {
        return $this->workflow->archiveNeedsApproval($project);
    }

    public function create(User $actor, array $input): Project
    {
        $this->access->assert($actor->id, 'projects.create');
        $tenantId = $this->resolveTenant($actor, $input);
        $ownerId = $this->resolveOwner($actor, $tenantId, $input['owner_id'] ?? null);

        $project = Project::create(Uuid::v7(), $tenantId, $input, $ownerId, $actor->id);
        $this->db->transaction(function () use ($project, $actor): void {
            $this->projects->insert($project);
            $this->projects->addMember($project->id, $project->ownerId(), 'owner', $actor->id);
            $this->audit->record('project.created', 'project', $project->id, null, $project->toArray(), category: 'data');
            $this->publish($project);
            $this->workflow->start($project, $actor->id);
        });
        return $this->projects->find($project->id) ?? $project;
    }

    public function update(User $actor, string $id, array $input, int $expectedVersion): Project
    {
        $this->access->assert($actor->id, 'projects.update');
        return $this->db->transaction(function () use ($actor, $id, $input, $expectedVersion): Project {
            $project = $this->loadForUpdate($actor, $id);
            $this->assertCanManage($actor, $project);
            $before = $project->toArray();
            if (array_key_exists('owner_id', $input) && (string) $input['owner_id'] !== $project->ownerId()) {
                $input['owner_id'] = $this->resolveOwner($actor, $project->tenantId, $input['owner_id']);
            }
            $changes = $project->update($input);
            if ($changes === []) {
                if ($project->version() !== $expectedVersion) {
                    throw new \App\Shared\Domain\Exception\ConflictException('The project was modified by someone else. Reload and try again.', 'stale_version');
                }
                return $project;
            }
            $this->projects->update($project, $expectedVersion);
            if (isset($changes['owner_id'])) {
                $this->projects->addMember($project->id, $project->ownerId(), 'owner', $actor->id);
            }
            $after = $this->projects->find($project->id);
            $this->audit->record('project.updated', 'project', $project->id, $before, $after?->toArray(), ['fields' => array_keys($changes)]);
            $this->publish($project);
            return $after ?? $project;
        });
    }

    public function changeStatus(User $actor, string $id, string $status, ?int $expectedVersion = null, string $comment = ''): Project
    {
        $to = ProjectStatus::tryFrom($status);
        if ($to === null) {
            throw ValidationException::single('status', 'Status must be one of: ' . implode(', ', ProjectStatus::values()) . '.');
        }
        if ($to === ProjectStatus::Archived) {
            return $this->archive($actor, $id, $expectedVersion, $comment)->project;
        }
        $this->access->assert($actor->id, 'projects.update');

        return $this->db->transaction(function () use ($actor, $id, $to, $expectedVersion, $comment): Project {
            $project = $this->loadForUpdate($actor, $id);
            $this->assertCanManage($actor, $project);
            if ($project->status() === ProjectStatus::Draft && $to === ProjectStatus::Active) {
                // Activating a draft is an approval decision
                $this->access->assert($actor->id, 'projects.approve');
            }
            return $this->transition($actor, $project, $to, $expectedVersion, $comment);
        });
    }

    /**
     * Archive = soft delete (kept for audit/reporting, read-only). Allowed from draft/completed.
     * With the "project.lifecycle" workflow enabled, archiving a completed project opens an approval
     * request (approvals.decide, four-eyes); the project is archived once the request is approved.
     */
    public function archive(User $actor, string $id, ?int $expectedVersion = null, string $comment = ''): ArchiveOutcome
    {
        $this->access->assert($actor->id, 'projects.delete');
        return $this->db->transaction(function () use ($actor, $id, $expectedVersion, $comment): ArchiveOutcome {
            $project = $this->loadForUpdate($actor, $id);
            $this->assertCanManage($actor, $project);
            $project->status()->assertTransitionTo(ProjectStatus::Archived);
            if ($expectedVersion !== null && $expectedVersion !== $project->version()) {
                throw new \App\Shared\Domain\Exception\ConflictException('The project was modified by someone else. Reload and try again.', 'stale_version');
            }
            if ($this->workflow->archiveNeedsApproval($project)) {
                $approvalId = $this->workflow->requestArchive($project, $actor->id, $comment);
                $this->audit->record('project.archive.requested', 'project', $project->id, null, ['approval_id' => $approvalId], ['comment' => $comment]);
                return new ArchiveOutcome($project, $approvalId);
            }
            return new ArchiveOutcome($this->transition($actor, $project, ProjectStatus::Archived, $expectedVersion, $comment));
        });
    }

    /** Applies an archive that was approved through the workflow (approval decided elsewhere). */
    private function finalizeApprovedArchive(Project $project): Project
    {
        $approverId = $this->workflow->approvedArchiveBy($project);
        if ($approverId === null) {
            return $project;
        }
        $approver = $this->users->find($approverId);
        if ($approver === null) {
            return $project;
        }
        try {
            return $this->db->transaction(function () use ($project, $approver): Project {
                $locked = $this->projects->findForUpdate($project->id);
                if ($locked === null || $locked->status() !== ProjectStatus::Completed) {
                    return $locked ?? $project;
                }
                return $this->transition($approver, $locked, ProjectStatus::Archived, null, 'Archive approved');
            });
        } catch (Throwable $e) {
            $this->logger->warning('Finalising approved archive failed: {e}', ['e' => $e->getMessage()]);
            return $project;
        }
    }

    public function addMember(User $actor, string $id, string $login, string $role): void
    {
        $this->access->assert($actor->id, 'projects.update');
        if (!in_array($role, ['manager', 'member', 'viewer'], true)) {
            throw ValidationException::single('role', 'Role must be manager, member or viewer.');
        }
        $project = $this->load($actor, $id);
        $this->assertCanManage($actor, $project);
        $project->assertEditable();
        $user = $this->users->findByLogin($login);
        if ($user === null || !$user->isActive() || $user->tenantId !== $project->tenantId) {
            throw ValidationException::single('login', 'No active user with this username or e-mail in the project tenant.');
        }
        if ($user->id === $project->ownerId()) {
            throw ValidationException::single('login', 'The owner is already a member.');
        }
        $this->db->transaction(function () use ($project, $user, $role, $actor): void {
            $this->projects->addMember($project->id, $user->id, $role, $actor->id);
            $this->audit->record('project.member.added', 'project', $project->id, null, ['user_id' => $user->id, 'role' => $role]);
        });
    }

    public function removeMember(User $actor, string $id, string $userId): void
    {
        $this->access->assert($actor->id, 'projects.update');
        $project = $this->load($actor, $id);
        $this->assertCanManage($actor, $project);
        if ($userId === $project->ownerId()) {
            throw ValidationException::single('user_id', 'The owner cannot be removed; transfer ownership first.');
        }
        $this->db->transaction(function () use ($project, $userId): void {
            if ($this->projects->removeMember($project->id, $userId)) {
                $this->audit->record('project.member.removed', 'project', $project->id, ['user_id' => $userId], null);
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function members(User $actor, string $id): array
    {
        $project = $this->get($actor, $id);
        return $this->projects->members($project->id);
    }

    /**
     * Timeline: the Kafka read model (project_activity) when populated, otherwise the audit trail.
     *
     * @return array{source:string, items:list<array<string,mixed>>}
     */
    public function activity(User $actor, string $id): array
    {
        $project = $this->get($actor, $id);
        $items = $this->projects->activity($project->id);
        if ($items !== []) {
            return ['source' => 'read_model', 'items' => $items];
        }
        $audit = array_map(static fn(array $r) => [
            'event_type' => $r['action'],
            'summary' => $r['action'],
            'actor_id' => $r['actor_id'],
            'occurred_at' => $r['occurred_at'],
        ], $this->projects->auditTrail($project->id));
        return ['source' => 'audit', 'items' => $audit];
    }

    public function canManage(User $actor, Project $project): bool
    {
        return $project->ownerId() === $actor->id || $this->access->can($actor->id, 'projects.manage_all');
    }

    /**
     * Enqueues the CSV summary report for the actor's tenant (background job, transactional enqueue).
     *
     * @return array{report_id:string, job_id:string}
     */
    public function requestReport(User $actor, array $filters = []): array
    {
        $this->access->assert($actor->id, 'projects.view');
        $tenantId = $this->resolveTenant($actor, $filters);
        $filters = array_intersect_key($filters, array_flip(['status', 'priority', 'q']));
        return $this->db->transaction(function () use ($actor, $tenantId, $filters): array {
            $reportId = Uuid::v7();
            $this->db->createCommand()->insert('project_reports', [
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
                'idempotency_key' => 'projects.report:' . $reportId,
            ]);
            $this->db->createCommand()->update('project_reports', ['job_id' => $jobId], ['id' => $reportId])->execute();
            $this->audit->record('project.report.requested', 'project_report', $reportId, null, ['tenant_id' => $tenantId, 'filters' => $filters, 'job_id' => $jobId]);
            return ['report_id' => $reportId, 'job_id' => $jobId];
        });
    }

    /** @return array<string,mixed> report row joined with job status */
    public function report(User $actor, string $reportId): array
    {
        $this->access->assert($actor->id, 'projects.view');
        if (!Uuid::isValid($reportId)) {
            throw new NotFoundException('Report not found.');
        }
        $row = (new Query($this->db))
            ->select(['r.*', 'job_status' => 'j.status', 'job_error' => 'j.last_error', 'job_progress' => 'j.progress'])
            ->from(['r' => 'project_reports'])->leftJoin(['j' => 'jobs'], 'j.id = r.job_id')
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
        $this->access->assert($actor->id, 'projects.view');
        $q = (new Query($this->db))
            ->select(['r.id', 'r.status', 'r.rows_count', 'r.size_bytes', 'r.created_at', 'r.finished_at', 'r.job_id', 'job_status' => 'j.status'])
            ->from(['r' => 'project_reports'])->leftJoin(['j' => 'jobs'], 'j.id = r.job_id')
            ->orderBy(['r.created_at' => SORT_DESC])->limit($limit);
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            $q->where(['r.tenant_id' => $restriction]);
        }
        return $q->all();
    }

    /** @return array<string,int> */
    public function countsByStatus(User $actor): array
    {
        $this->access->assert($actor->id, 'projects.view');
        return $this->projects->countsByStatus($this->tenantRestriction($actor));
    }

    public function tenantRestriction(User $actor): ?string
    {
        if ($this->access->can($actor->id, 'tenants.cross')) {
            return null;
        }
        return $actor->tenantId ?? '00000000-0000-0000-0000-000000000000';
    }

    // ------------------------------------------------------------------ internals

    private function transition(User $actor, Project $project, ProjectStatus $to, ?int $expectedVersion, string $comment): Project
    {
        $expected = $expectedVersion ?? $project->version();
        if ($expectedVersion !== null && $expectedVersion !== $project->version()) {
            throw new \App\Shared\Domain\Exception\ConflictException('The project was modified by someone else. Reload and try again.', 'stale_version');
        }
        $before = $project->toArray();
        $from = $project->changeStatus($to, $comment);
        $this->projects->update($project, $expected);
        $after = $this->projects->find($project->id) ?? $project;
        $this->audit->record(
            $to === ProjectStatus::Archived ? 'project.archived' : 'project.status_changed',
            'project',
            $project->id,
            ['status' => $before['status']],
            ['status' => $to->value],
            ['from' => $from->value, 'to' => $to->value, 'comment' => $comment],
            severity: $to === ProjectStatus::Archived ? 'notice' : 'info',
        );
        $this->publish($project);
        $this->workflow->mirror($project, $from, $to, $actor->id, $comment);
        $this->notifyStatusChange($actor, $after, $to);
        return $after;
    }

    /** Writes the aggregate's pending domain events to the outbox (same transaction). */
    private function publish(Project $project): void
    {
        foreach ($project->pullEvents() as $event) {
            $this->outbox->record(ProjectEvent::TOPIC, $event->type, 'project', $event->projectId, $event->payload + [
                'tenant_id' => $project->tenantId,
                'code' => $project->code(),
                'name' => $project->name(),
                'status' => $project->status()->value,
            ]);
        }
    }

    private function notifyStatusChange(User $actor, Project $project, ProjectStatus $to): void
    {
        $recipients = array_unique([$project->ownerId(), ...array_column($this->projects->members($project->id), 'user_id')]);
        foreach ($recipients as $userId) {
            if ($userId === $actor->id) {
                continue; // the actor knows already
            }
            try {
                $this->notifier->notify((string) $userId, self::NOTIFY_TEMPLATE, [
                    'project' => $project->name(),
                    'status' => $to->label(),
                    'actor' => $actor->displayName(),
                ], ['inapp', 'email'], 'projects');
            } catch (Throwable $e) {
                // Notifications must never break the business transaction
                $this->logger->warning('Project notification failed: {e}', ['e' => $e->getMessage()]);
            }
        }
    }

    private function load(User $actor, string $id): Project
    {
        $project = $this->projects->find($id);
        $this->assertVisible($actor, $project);
        return $project;
    }

    private function loadForUpdate(User $actor, string $id): Project
    {
        $project = $this->projects->findForUpdate($id);
        $this->assertVisible($actor, $project);
        return $project;
    }

    /** @psalm-assert Project $project */
    private function assertVisible(User $actor, ?Project $project): void
    {
        $restriction = $this->tenantRestriction($actor);
        if ($project === null || ($restriction !== null && $project->tenantId !== $restriction)) {
            // IDOR protection: foreign-tenant projects are reported as non-existent
            throw new NotFoundException('Project not found.');
        }
    }

    private function assertCanManage(User $actor, Project $project): void
    {
        if (!$this->canManage($actor, $project)) {
            $this->audit->security('access.denied', 'denied', ['permission' => 'projects.manage_all', 'project' => $project->id], 'notice', $actor->id);
            throw new AccessDeniedException('Only the project owner or a projects manager can change this project.', 'projects.manage_all');
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

    private function resolveOwner(User $actor, string $tenantId, mixed $ownerId): string
    {
        if ($ownerId === null || $ownerId === '' || $ownerId === $actor->id) {
            return $actor->id;
        }
        if (!is_string($ownerId) || !Uuid::isValid($ownerId)) {
            throw ValidationException::single('owner_id', 'Owner must be a valid user id.');
        }
        if (!$this->access->can($actor->id, 'projects.manage_all')) {
            throw new AccessDeniedException('Only a projects manager can assign another owner.', 'projects.manage_all');
        }
        $owner = $this->users->find($ownerId);
        if ($owner === null || !$owner->isActive() || $owner->tenantId !== $tenantId) {
            throw ValidationException::single('owner_id', 'The owner must be an active user of the project tenant.');
        }
        return $owner->id;
    }
}
