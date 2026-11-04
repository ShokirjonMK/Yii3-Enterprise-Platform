<?php

declare(strict_types=1);

namespace App\Modules\Projects\Application;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Platform\Workflow\WorkflowEngine;
use App\Shared\Infrastructure\Database\Sql;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Bridge between the Project aggregate and the platform WorkflowEngine definition "project.lifecycle"
 * (seeded by the workflow work package). The domain state machine stays authoritative for which
 * transitions are legal; the workflow instance mirrors the project state and adds the approval gate:
 *
 *   completed → archived requires an approval (approvals.decide, four-eyes) when the workflow is enabled.
 *
 * When the workflow definition is missing or disabled every method is a no-op and the domain rules alone apply.
 */
final class ProjectWorkflow
{
    public const CODE = 'project.lifecycle';
    public const ENTITY = 'project';

    /** Transition names of the seeded definition, by target state. */
    private const TRANSITION = [
        'active' => ['draft' => 'activate', 'on_hold' => 'resume'],
        'on_hold' => ['active' => 'hold'],
        'completed' => ['active' => 'complete', 'on_hold' => 'complete'],
        'archived' => ['completed' => 'archive'],
    ];

    /** Path from the initial state to the given state (to catch up instances created late). */
    private const PATH = [
        'draft' => [],
        'active' => ['activate'],
        'on_hold' => ['activate', 'hold'],
        'completed' => ['activate', 'complete'],
    ];

    private ?bool $enabled = null;

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function enabled(): bool
    {
        if ($this->enabled === null) {
            $row = (new Query($this->db))->select(['enabled'])->from('workflows')->where(['code' => self::CODE, 'entity_type' => self::ENTITY])->one();
            $this->enabled = $row !== null && Sql::bool($row['enabled']);
        }
        return $this->enabled;
    }

    /** Whether archiving this project goes through an approval request. */
    public function archiveNeedsApproval(Project $project): bool
    {
        return $this->enabled() && $project->status() === ProjectStatus::Completed;
    }

    public function start(Project $project, string $actorId): void
    {
        if ($this->enabled()) {
            $this->engine->start(self::CODE, self::ENTITY, $project->id, $actorId, $project->tenantId, ['code' => $project->code()]);
        }
    }

    /** Mirrors a direct (non-approval) project transition onto the workflow instance. */
    public function mirror(Project $project, ProjectStatus $from, ProjectStatus $to, string $actorId, string $comment): void
    {
        if (!$this->enabled() || $to === ProjectStatus::Archived) {
            return;
        }
        $instance = $this->instance($project, $actorId, $from);
        $name = self::TRANSITION[$to->value][$from->value] ?? null;
        if ($instance === null || $name === null || $instance['state'] !== $from->value) {
            return;
        }
        $this->engine->apply((string) $instance['id'], $name, $actorId, (int) $instance['version'], $comment);
    }

    /**
     * Opens the approval request for completed → archived.
     *
     * @return string approval request id
     */
    public function requestArchive(Project $project, string $actorId, string $comment): string
    {
        $instance = $this->instance($project, $actorId, $project->status());
        if ($instance === null) {
            throw new \LogicException('Project workflow instance could not be created.');
        }
        $result = $this->engine->apply((string) $instance['id'], 'archive', $actorId, (int) $instance['version'], $comment);
        return (string) $result['approval_id'];
    }

    /** Pending archive approval request id, if any. */
    public function pendingArchive(Project $project): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        $id = (new Query($this->db))->select('r.id')->from(['r' => 'approval_requests'])
            ->innerJoin(['i' => 'workflow_instances'], 'i.id = r.instance_id')
            ->innerJoin(['w' => 'workflows'], 'w.id = i.workflow_id')
            ->where(['w.code' => self::CODE, 'i.entity_id' => $project->id, 'r.transition' => 'archive', 'r.status' => 'pending'])
            ->scalar();
        return $id === false || $id === null ? null : (string) $id;
    }

    /**
     * After an approver accepted the archive request the workflow instance is "archived" while the project
     * is still "completed": returns the approver id so the service can apply the domain transition.
     */
    public function approvedArchiveBy(Project $project): ?string
    {
        if (!$this->enabled() || $project->status() !== ProjectStatus::Completed) {
            return null;
        }
        $row = $this->engine->instanceFor(self::CODE, self::ENTITY, $project->id);
        if ($row === null || $row['state'] !== ProjectStatus::Archived->value) {
            return null;
        }
        $actor = (new Query($this->db))->select('actor_id')->from('workflow_transitions')
            ->where(['instance_id' => $row['id'], 'to_state' => 'archived'])->orderBy(['id' => SORT_DESC])->scalar();
        return $actor === false || $actor === null ? null : (string) $actor;
    }

    /** Existing instance, or a new one caught up to $state (projects created before the workflow existed). */
    private function instance(Project $project, string $actorId, ProjectStatus $state): ?array
    {
        $row = $this->engine->instanceFor(self::CODE, self::ENTITY, $project->id);
        if ($row !== null) {
            return $row;
        }
        try {
            $row = $this->engine->start(self::CODE, self::ENTITY, $project->id, $actorId, $project->tenantId, ['code' => $project->code()]);
            foreach (self::PATH[$state->value] ?? [] as $name) {
                $this->engine->apply((string) $row['id'], $name, $actorId, null, 'Synchronised with project state');
                $row = $this->engine->instance((string) $row['id']);
            }
            return $row;
        } catch (Throwable $e) {
            $this->logger->warning('Project workflow sync failed: {e}', ['e' => $e->getMessage()]);
            return null;
        }
    }
}
