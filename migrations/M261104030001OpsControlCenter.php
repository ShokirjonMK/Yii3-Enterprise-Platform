<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Operations control center (sections 15-20, 24):
 *  - deployments: GitLab pipeline id, rollback link, rejection actor, last error
 *  - backups: off-site copy location, source database, "expired" status (retention), queue job id
 *  - health_probe_state: consecutive failure counters for the health probe (auto incidents)
 */
final class M261104030001OpsControlCenter implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            ALTER TABLE deployments
                ADD COLUMN pipeline_id  bigint,
                ADD COLUMN rollback_of  uuid REFERENCES deployments (id) ON DELETE SET NULL,
                ADD COLUMN rejected_by  uuid,
                ADD COLUMN error        text;
            CREATE INDEX deployments_status_idx ON deployments (status, created_at DESC);

            ALTER TABLE backups DROP CONSTRAINT backups_status_check;
            ALTER TABLE backups ADD CONSTRAINT backups_status_check CHECK (status IN ('running','succeeded','failed','expired'));
            ALTER TABLE backups
                ADD COLUMN database_name    varchar(128),
                ADD COLUMN offsite_location varchar(1024),
                ADD COLUMN job_id           uuid;

            CREATE TABLE health_probe_state (
                component             varchar(64)  PRIMARY KEY,
                status                varchar(16)  NOT NULL,
                consecutive_failures  integer      NOT NULL DEFAULT 0,
                details               jsonb        NOT NULL DEFAULT '{}'::jsonb,
                last_checked_at       timestamptz  NOT NULL DEFAULT now(),
                last_change_at        timestamptz  NOT NULL DEFAULT now()
            );

            CREATE INDEX incident_events_incident_idx ON incident_events (incident_id, created_at);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP INDEX IF EXISTS incident_events_incident_idx;
            DROP TABLE IF EXISTS health_probe_state;
            ALTER TABLE backups DROP COLUMN IF EXISTS job_id, DROP COLUMN IF EXISTS offsite_location, DROP COLUMN IF EXISTS database_name;
            UPDATE backups SET status = 'failed' WHERE status = 'expired';
            ALTER TABLE backups DROP CONSTRAINT backups_status_check;
            ALTER TABLE backups ADD CONSTRAINT backups_status_check CHECK (status IN ('running','succeeded','failed'));
            DROP INDEX IF EXISTS deployments_status_idx;
            ALTER TABLE deployments DROP COLUMN IF EXISTS error, DROP COLUMN IF EXISTS rejected_by,
                DROP COLUMN IF EXISTS rollback_of, DROP COLUMN IF EXISTS pipeline_id;
            SQL);
    }
}
