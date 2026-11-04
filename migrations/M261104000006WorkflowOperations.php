<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Workflow & approvals, incidents, backups, deployments, file storage metadata.
 */
final class M261104000006WorkflowOperations implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE workflows (
                id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code         varchar(96)  NOT NULL UNIQUE,
                name         varchar(255) NOT NULL,
                entity_type  varchar(96)  NOT NULL,
                definition   jsonb        NOT NULL,
                enabled      boolean      NOT NULL DEFAULT true,
                version      integer      NOT NULL DEFAULT 1,
                created_at   timestamptz  NOT NULL DEFAULT now(),
                updated_at   timestamptz  NOT NULL DEFAULT now()
            );

            CREATE TABLE workflow_instances (
                id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                workflow_id  uuid         NOT NULL REFERENCES workflows (id) ON DELETE RESTRICT,
                entity_type  varchar(96)  NOT NULL,
                entity_id    varchar(96)  NOT NULL,
                tenant_id    uuid,
                state        varchar(64)  NOT NULL,
                data         jsonb        NOT NULL DEFAULT '{}'::jsonb,
                started_by   uuid,
                version      integer      NOT NULL DEFAULT 1,
                created_at   timestamptz  NOT NULL DEFAULT now(),
                updated_at   timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT workflow_instances_entity_uq UNIQUE (workflow_id, entity_type, entity_id)
            );
            CREATE INDEX workflow_instances_state_idx ON workflow_instances (workflow_id, state);

            CREATE TABLE workflow_transitions (
                id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                instance_id   uuid         NOT NULL REFERENCES workflow_instances (id) ON DELETE CASCADE,
                transition    varchar(64)  NOT NULL,
                from_state    varchar(64)  NOT NULL,
                to_state      varchar(64)  NOT NULL,
                actor_id      uuid,
                comment       text,
                created_at    timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX workflow_transitions_instance_idx ON workflow_transitions (instance_id, created_at);

            CREATE TABLE approval_requests (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id           uuid,
                type                varchar(64)  NOT NULL,
                title               varchar(255) NOT NULL,
                entity_type         varchar(96),
                entity_id           varchar(96),
                instance_id         uuid         REFERENCES workflow_instances (id) ON DELETE CASCADE,
                transition          varchar(64),
                payload             jsonb        NOT NULL DEFAULT '{}'::jsonb,
                approver_permission varchar(128) NOT NULL,
                required_approvals  smallint     NOT NULL DEFAULT 1 CHECK (required_approvals BETWEEN 1 AND 10),
                status              varchar(16)  NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','approved','rejected','cancelled','expired')),
                requested_by        uuid         NOT NULL,
                created_at          timestamptz  NOT NULL DEFAULT now(),
                expires_at          timestamptz,
                decided_at          timestamptz
            );
            CREATE INDEX approval_requests_status_idx ON approval_requests (status, created_at DESC);

            CREATE TABLE approval_decisions (
                id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                request_id  uuid         NOT NULL REFERENCES approval_requests (id) ON DELETE CASCADE,
                approver_id uuid         NOT NULL,
                decision    varchar(16)  NOT NULL CHECK (decision IN ('approve','reject')),
                comment     text,
                created_at  timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT approval_decisions_once UNIQUE (request_id, approver_id)
            );

            CREATE SEQUENCE incident_number_seq;
            CREATE TABLE incidents (
                id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                number           bigint       NOT NULL DEFAULT nextval('incident_number_seq') UNIQUE,
                title            varchar(255) NOT NULL,
                severity         varchar(8)   NOT NULL CHECK (severity IN ('sev1','sev2','sev3','sev4')),
                status           varchar(16)  NOT NULL DEFAULT 'open'
                    CHECK (status IN ('open','investigating','identified','monitoring','resolved','closed')),
                service          varchar(96)  NOT NULL DEFAULT 'platform',
                description      text         NOT NULL DEFAULT '',
                commander_id     uuid,
                opened_by        uuid,
                source           varchar(32)  NOT NULL DEFAULT 'manual',
                fingerprint      varchar(128),
                opened_at        timestamptz  NOT NULL DEFAULT now(),
                acknowledged_at  timestamptz,
                resolved_at      timestamptz,
                closed_at        timestamptz,
                postmortem       text,
                updated_at       timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX incidents_status_idx ON incidents (status, severity, opened_at DESC);
            CREATE UNIQUE INDEX incidents_open_fingerprint_uq ON incidents (fingerprint) WHERE fingerprint IS NOT NULL AND status NOT IN ('resolved','closed');

            CREATE TABLE incident_events (
                id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                incident_id  uuid         NOT NULL REFERENCES incidents (id) ON DELETE CASCADE,
                type         varchar(32)  NOT NULL,
                body         text         NOT NULL,
                actor_id     uuid,
                created_at   timestamptz  NOT NULL DEFAULT now()
            );

            CREATE TABLE backups (
                id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                type            varchar(16)  NOT NULL CHECK (type IN ('logical','basebackup','wal')),
                status          varchar(16)  NOT NULL DEFAULT 'running' CHECK (status IN ('running','succeeded','failed')),
                location        varchar(1024),
                size_bytes      bigint,
                checksum_sha256 char(64),
                started_at      timestamptz  NOT NULL DEFAULT now(),
                finished_at     timestamptz,
                expires_at      timestamptz,
                verify_status   varchar(16)  CHECK (verify_status IN ('pending','passed','failed')),
                verified_at     timestamptz,
                verify_details  jsonb,
                initiated_by    uuid,
                error           text
            );
            CREATE INDEX backups_started_idx ON backups (started_at DESC);

            CREATE TABLE deployments (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                environment   varchar(32)  NOT NULL,
                version       varchar(64)  NOT NULL,
                commit_sha    varchar(64),
                status        varchar(16)  NOT NULL DEFAULT 'requested'
                    CHECK (status IN ('requested','approved','rejected','running','succeeded','failed','rolled_back')),
                strategy      varchar(16)  NOT NULL DEFAULT 'rolling' CHECK (strategy IN ('rolling','canary','bluegreen')),
                pipeline_url  varchar(1024),
                notes         text,
                requested_by  uuid,
                approved_by   uuid,
                created_at    timestamptz  NOT NULL DEFAULT now(),
                updated_at    timestamptz  NOT NULL DEFAULT now(),
                finished_at   timestamptz,
                CONSTRAINT deployments_four_eyes CHECK (approved_by IS NULL OR approved_by <> requested_by)
            );
            CREATE INDEX deployments_env_idx ON deployments (environment, created_at DESC);

            CREATE TABLE stored_files (
                id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id      uuid,
                owner_id       uuid,
                disk           varchar(32)  NOT NULL DEFAULT 's3',
                path           varchar(1024) NOT NULL,
                original_name  varchar(255) NOT NULL,
                mime_type      varchar(128) NOT NULL,
                size_bytes     bigint       NOT NULL CHECK (size_bytes >= 0),
                checksum_sha256 char(64)    NOT NULL,
                entity_type    varchar(96),
                entity_id      varchar(96),
                created_at     timestamptz  NOT NULL DEFAULT now(),
                deleted_at     timestamptz
            );
            CREATE INDEX stored_files_entity_idx ON stored_files (entity_type, entity_id);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS stored_files, deployments, backups, incident_events, incidents, approval_decisions,
                approval_requests, workflow_transitions, workflow_instances, workflows CASCADE;
            DROP SEQUENCE IF EXISTS incident_number_seq;
            SQL);
    }
}
