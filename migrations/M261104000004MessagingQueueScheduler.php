<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Transactional outbox, idempotent-consumer inbox, Kafka DLQ mirror, durable job queue,
 * worker registry, scheduler.
 */
final class M261104000004MessagingQueueScheduler implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE outbox (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                aggregate_type  varchar(96)  NOT NULL,
                aggregate_id    varchar(96)  NOT NULL,
                event_type      varchar(128) NOT NULL,
                schema_version  smallint     NOT NULL DEFAULT 1,
                topic           varchar(249) NOT NULL,
                partition_key   varchar(255) NOT NULL,
                payload         jsonb        NOT NULL,
                headers         jsonb        NOT NULL DEFAULT '{}'::jsonb,
                status          varchar(16)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','published','failed')),
                attempts        integer      NOT NULL DEFAULT 0,
                available_at    timestamptz  NOT NULL DEFAULT now(),
                published_at    timestamptz,
                last_error      text,
                correlation_id  varchar(128),
                tenant_id       uuid,
                created_at      timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX outbox_pending_idx ON outbox (available_at, created_at) WHERE status = 'pending';
            CREATE INDEX outbox_status_idx ON outbox (status, created_at DESC);
            CREATE INDEX outbox_aggregate_idx ON outbox (aggregate_type, aggregate_id);

            CREATE TABLE inbox (
                consumer_group  varchar(128) NOT NULL,
                message_id      varchar(128) NOT NULL,
                topic           varchar(249) NOT NULL,
                processed_at    timestamptz  NOT NULL DEFAULT now(),
                PRIMARY KEY (consumer_group, message_id)
            );
            CREATE INDEX inbox_processed_idx ON inbox (processed_at);

            CREATE TABLE kafka_dead_letters (
                id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                consumer_group  varchar(128) NOT NULL,
                topic           varchar(249) NOT NULL,
                partition       integer      NOT NULL,
                "offset"        bigint       NOT NULL,
                message_key     varchar(255),
                message_id      varchar(128),
                payload         text         NOT NULL,
                headers         jsonb        NOT NULL DEFAULT '{}'::jsonb,
                error           text         NOT NULL,
                attempts        integer      NOT NULL DEFAULT 1,
                failed_at       timestamptz  NOT NULL DEFAULT now(),
                replayed_at     timestamptz,
                replayed_by     uuid
            );
            CREATE INDEX kafka_dead_letters_failed_idx ON kafka_dead_letters (failed_at DESC);

            CREATE TABLE kafka_topics (
                name                varchar(249) PRIMARY KEY,
                partitions          integer      NOT NULL DEFAULT 3 CHECK (partitions BETWEEN 1 AND 1000),
                replication_factor  smallint     NOT NULL DEFAULT 1 CHECK (replication_factor BETWEEN 1 AND 7),
                retention_ms        bigint       NOT NULL DEFAULT 604800000,
                cleanup_policy      varchar(16)  NOT NULL DEFAULT 'delete' CHECK (cleanup_policy IN ('delete','compact','compact,delete')),
                schema_version      smallint     NOT NULL DEFAULT 1,
                description         text         NOT NULL DEFAULT '',
                provisioned_at      timestamptz,
                created_at          timestamptz  NOT NULL DEFAULT now()
            );

            CREATE TABLE consumer_state (
                consumer_group  varchar(128) PRIMARY KEY,
                paused          boolean      NOT NULL DEFAULT false,
                paused_by       uuid,
                paused_at       timestamptz,
                updated_at      timestamptz  NOT NULL DEFAULT now()
            );

            CREATE TABLE jobs (
                id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                queue            varchar(64)  NOT NULL DEFAULT 'default',
                type             varchar(255) NOT NULL,
                payload          jsonb        NOT NULL DEFAULT '{}'::jsonb,
                status           varchar(16)  NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','running','succeeded','failed','cancelled','dead')),
                priority         smallint     NOT NULL DEFAULT 100,
                attempts         integer      NOT NULL DEFAULT 0,
                max_attempts     integer      NOT NULL DEFAULT 5 CHECK (max_attempts BETWEEN 1 AND 100),
                timeout_seconds  integer      NOT NULL DEFAULT 300 CHECK (timeout_seconds BETWEEN 1 AND 86400),
                backoff_seconds  integer      NOT NULL DEFAULT 10,
                available_at     timestamptz  NOT NULL DEFAULT now(),
                reserved_by      varchar(128),
                reserved_until   timestamptz,
                started_at       timestamptz,
                finished_at      timestamptz,
                last_error       text,
                progress         smallint     NOT NULL DEFAULT 0,
                cancel_requested boolean      NOT NULL DEFAULT false,
                idempotency_key  varchar(255),
                correlation_id   varchar(128),
                tenant_id        uuid,
                created_by       uuid,
                created_at       timestamptz  NOT NULL DEFAULT now(),
                updated_at       timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX jobs_fetch_idx ON jobs (queue, priority, available_at) WHERE status = 'pending';
            CREATE INDEX jobs_running_idx ON jobs (reserved_until) WHERE status = 'running';
            CREATE INDEX jobs_status_idx ON jobs (status, created_at DESC);
            CREATE UNIQUE INDEX jobs_idempotency_uq ON jobs (idempotency_key) WHERE idempotency_key IS NOT NULL;
            CREATE TRIGGER jobs_touch BEFORE UPDATE ON jobs FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();

            CREATE TABLE job_attempts (
                id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                job_id       uuid         NOT NULL REFERENCES jobs (id) ON DELETE CASCADE,
                attempt      integer      NOT NULL,
                worker_id    varchar(128),
                started_at   timestamptz  NOT NULL,
                finished_at  timestamptz,
                status       varchar(16)  NOT NULL,
                error        text,
                duration_ms  integer
            );
            CREATE INDEX job_attempts_job_idx ON job_attempts (job_id, attempt);

            CREATE TABLE queue_state (
                queue      varchar(64) PRIMARY KEY,
                paused     boolean     NOT NULL DEFAULT false,
                paused_by  uuid,
                paused_at  timestamptz
            );

            CREATE TABLE workers (
                id              varchar(128) PRIMARY KEY,
                type            varchar(32)  NOT NULL DEFAULT 'queue',
                hostname        varchar(255) NOT NULL,
                pid             integer      NOT NULL,
                queues          varchar(255) NOT NULL DEFAULT '',
                status          varchar(16)  NOT NULL DEFAULT 'idle' CHECK (status IN ('idle','busy','stopping','stopped','lost')),
                current_job_id  uuid,
                jobs_processed  bigint       NOT NULL DEFAULT 0,
                jobs_failed     bigint       NOT NULL DEFAULT 0,
                memory_bytes    bigint       NOT NULL DEFAULT 0,
                started_at      timestamptz  NOT NULL DEFAULT now(),
                heartbeat_at    timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX workers_heartbeat_idx ON workers (heartbeat_at);

            CREATE TABLE schedules (
                id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code             varchar(96)  NOT NULL UNIQUE,
                name             varchar(255) NOT NULL,
                cron_expression  varchar(128) NOT NULL,
                timezone         varchar(64)  NOT NULL DEFAULT 'Asia/Tashkent',
                job_type         varchar(255) NOT NULL,
                payload          jsonb        NOT NULL DEFAULT '{}'::jsonb,
                queue            varchar(64)  NOT NULL DEFAULT 'default',
                enabled          boolean      NOT NULL DEFAULT true,
                overlap          varchar(8)   NOT NULL DEFAULT 'skip' CHECK (overlap IN ('skip','allow')),
                is_system        boolean      NOT NULL DEFAULT false,
                last_run_at      timestamptz,
                next_run_at      timestamptz,
                last_status      varchar(16),
                last_error       text,
                last_job_id      uuid,
                created_at       timestamptz  NOT NULL DEFAULT now(),
                updated_at       timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX schedules_due_idx ON schedules (next_run_at) WHERE enabled;
            CREATE TRIGGER schedules_touch BEFORE UPDATE ON schedules FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();

            CREATE TABLE schedule_runs (
                id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                schedule_id   uuid         NOT NULL REFERENCES schedules (id) ON DELETE CASCADE,
                scheduled_for timestamptz  NOT NULL,
                job_id        uuid,
                status        varchar(16)  NOT NULL,
                message       text,
                host          varchar(255),
                created_at    timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT schedule_runs_once UNIQUE (schedule_id, scheduled_for)
            );
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, 'DROP TABLE IF EXISTS schedule_runs, schedules, workers, queue_state, job_attempts, jobs, consumer_state, kafka_topics, kafka_dead_letters, inbox, outbox CASCADE');
    }
}
