<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Developer Tools → Enterprise Gii → "Next steps" runs (migrate:up, rbac:sync, Kafka topics) started from the admin
 * panel: per-step status / exit code / duration and the full console log, so the page can show progress live.
 *
 * Author: ShokirjonMK
 */
final class M261105000004DevtoolsRuns implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE devtools_runs (
                id           uuid PRIMARY KEY,
                kind         varchar(32) NOT NULL DEFAULT 'gii.next_steps',
                subject      varchar(80) NOT NULL DEFAULT '',
                status       varchar(16) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','running','succeeded','failed')),
                mode         varchar(8)  NOT NULL DEFAULT 'queue' CHECK (mode IN ('queue','inline')),
                steps        jsonb NOT NULL DEFAULT '[]',
                log          text  NOT NULL DEFAULT '',
                job_id       uuid,
                created_by   uuid,
                created_at   timestamptz NOT NULL DEFAULT now(),
                started_at   timestamptz,
                finished_at  timestamptz
            );
            CREATE INDEX devtools_runs_created_idx ON devtools_runs (created_at DESC);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, 'DROP TABLE IF EXISTS devtools_runs;');
    }
}
