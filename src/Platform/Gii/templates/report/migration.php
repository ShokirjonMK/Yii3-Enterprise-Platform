<?php

declare(strict_types=1);

/**
 * Template: report table migration.
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
 * {{label}} reports: one row per requested CSV report (job __JOB__).
 */
final class __CLASS__ implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE {{entity_snake}}_reports (
                id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id        uuid         NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                job_id           uuid,
                status           varchar(16)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','running','done','failed')),
                filters          jsonb        NOT NULL DEFAULT '{}'::jsonb,
                path             varchar(1024),
                rows_count       integer,
                size_bytes       bigint,
                checksum_sha256  char(64),
                error            text,
                requested_by     uuid,
                created_at       timestamptz  NOT NULL DEFAULT now(),
                finished_at      timestamptz
            );
            CREATE INDEX {{entity_snake}}_reports_tenant_idx ON {{entity_snake}}_reports (tenant_id, created_at DESC);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, 'DROP TABLE IF EXISTS {{entity_snake}}_reports');
    }
}

PHP, ['__CLASS__' => $m->migrationClass('Reports'), '__JOB__' => $m->reportJob()]);
