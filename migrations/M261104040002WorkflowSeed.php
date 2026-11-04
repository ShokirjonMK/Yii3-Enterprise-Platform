<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Seeds the workflow definition "project.lifecycle" (entity_type "project") used by the Projects module:
 *   draft → active ⇄ on_hold → completed → archived   (archive requires one approval, four-eyes)
 * Transitions carry no permission of their own — the Projects module authorises access to the project;
 * operators can add e.g. "projects.update" in Admin → Workflow. Also schedules expiry of overdue approvals.
 */
final class M261104040002WorkflowSeed implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $definition = json_encode([
            'states' => ['draft', 'active', 'on_hold', 'completed', 'archived'],
            'initial' => 'draft',
            'transitions' => [
                'activate' => ['label' => 'Activate', 'from' => ['draft'], 'to' => 'active'],
                'hold' => ['label' => 'Put on hold', 'from' => ['active'], 'to' => 'on_hold'],
                'resume' => ['label' => 'Resume', 'from' => ['on_hold'], 'to' => 'active'],
                'complete' => ['label' => 'Complete', 'from' => ['active', 'on_hold'], 'to' => 'completed'],
                'reopen' => ['label' => 'Reopen', 'from' => ['completed'], 'to' => 'active'],
                'archive' => [
                    'label' => 'Archive', 'from' => ['completed'], 'to' => 'archived',
                    'approval' => ['permission' => 'approvals.decide', 'required_approvals' => 1, 'expires_hours' => 168],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);
        $b->getDb()->createCommand(
            "INSERT INTO workflows (code, name, entity_type, definition, enabled) VALUES ('project.lifecycle', 'Project lifecycle', 'project', CAST(:d AS jsonb), true)
             ON CONFLICT (code) DO NOTHING",
            [':d' => $definition],
        )->execute();
        $b->getDb()->createCommand(
            "INSERT INTO schedules (code, name, cron_expression, job_type, payload, enabled, is_system, queue)
             VALUES ('platform.approvals.expire', 'Expire overdue approval requests', '*/5 * * * *', 'platform.approvals.expire', '{}'::jsonb, true, true, 'default')
             ON CONFLICT (code) DO NOTHING",
        )->execute();
    }

    public function down(MigrationBuilder $b): void
    {
        $b->getDb()->createCommand("DELETE FROM schedules WHERE code = 'platform.approvals.expire'")->execute();
        $b->getDb()->createCommand("DELETE FROM workflows WHERE code = 'project.lifecycle' AND NOT EXISTS (SELECT 1 FROM workflow_instances i WHERE i.workflow_id = workflows.id)")->execute();
    }
}
