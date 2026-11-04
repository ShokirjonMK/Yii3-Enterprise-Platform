<?php

declare(strict_types=1);

/**
 * Template: schedule seed (table `schedules`).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Schedule "__JOB__": runs job __JOB__ nightly at 02:40 (Asia/Tashkent) through the platform Scheduler
 * (leader election + UNIQUE schedule_runs → exactly once per tick; overlap = skip). Operators can change
 * the cron expression, payload or disable it in Admin → Jobs → Schedules; existing rows are kept.
 */
final class __CLASS__ implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->getDb()->createCommand(
            "INSERT INTO schedules (code, name, cron_expression, job_type, payload, enabled, is_system, queue)
             VALUES ('__JOB__', 'Purge deleted {{labelLower}}', '40 2 * * *', '__JOB__', CAST(:p AS jsonb), true, false, 'default')
             ON CONFLICT (code) DO NOTHING",
            [':p' => json_encode(['retention_days' => 90])],
        )->execute();
    }

    public function down(MigrationBuilder $b): void
    {
        $b->getDb()->createCommand("DELETE FROM schedules WHERE code = '__JOB__'")->execute();
    }
}

PHP, ['__CLASS__' => $m->migrationClass('Schedule'), '__JOB__' => $m->cleanupJob()]);
