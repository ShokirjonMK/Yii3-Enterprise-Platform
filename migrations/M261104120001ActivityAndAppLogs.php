<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * User activity log (every authenticated request: who, what, when, from where, result, duration) and
 * application log store (warning+ log records) — both monthly-partitioned, both switchable by feature flags
 * (audit.user_activity, logging.database). Also registers the feature flags for all optional platform features.
 *
 * Author: ShokirjonMK
 */
final class M261104120001ActivityAndAppLogs implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE user_activity (
                id              bigint GENERATED ALWAYS AS IDENTITY,
                occurred_at     timestamptz  NOT NULL DEFAULT now(),
                user_id         uuid,
                username        varchar(64),
                tenant_id       uuid,
                channel         varchar(16)  NOT NULL,
                method          varchar(8)   NOT NULL,
                route           varchar(128),
                path            varchar(512) NOT NULL,
                status          smallint     NOT NULL,
                duration_ms     integer      NOT NULL,
                ip              inet,
                user_agent      varchar(255),
                correlation_id  varchar(128),
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at);
            CREATE TABLE user_activity_default PARTITION OF user_activity DEFAULT;
            SELECT platform_ensure_monthly_partitions('user_activity', 0, 3);
            CREATE INDEX user_activity_user_idx ON user_activity (user_id, occurred_at DESC);
            CREATE INDEX user_activity_time_idx ON user_activity (occurred_at DESC);
            CREATE INDEX user_activity_route_idx ON user_activity (route, occurred_at DESC);

            CREATE TABLE app_logs (
                id              bigint GENERATED ALWAYS AS IDENTITY,
                occurred_at     timestamptz  NOT NULL DEFAULT now(),
                level           varchar(16)  NOT NULL,
                category        varchar(128) NOT NULL DEFAULT 'application',
                message         text         NOT NULL,
                context         jsonb        NOT NULL DEFAULT '{}'::jsonb,
                channel         varchar(16),
                user_id         uuid,
                tenant_id       uuid,
                correlation_id  varchar(128),
                host            varchar(128),
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at);
            CREATE TABLE app_logs_default PARTITION OF app_logs DEFAULT;
            SELECT platform_ensure_monthly_partitions('app_logs', 0, 3);
            CREATE INDEX app_logs_time_idx ON app_logs (occurred_at DESC);
            CREATE INDEX app_logs_level_idx ON app_logs (level, occurred_at DESC);
            CREATE INDEX app_logs_correlation_idx ON app_logs (correlation_id);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, 'DROP TABLE IF EXISTS user_activity, app_logs CASCADE');
    }
}
