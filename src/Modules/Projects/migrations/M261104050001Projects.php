<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Projects sample business module: projects, members, activity read model (Kafka consumer side)
 * and generated reports.
 */
final class M261104050001Projects implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE projects (
                id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id    uuid          NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                code         varchar(32)   NOT NULL,
                name         varchar(200)  NOT NULL,
                description  text          NOT NULL DEFAULT '',
                status       varchar(16)   NOT NULL DEFAULT 'draft'
                    CHECK (status IN ('draft','active','on_hold','completed','archived')),
                priority     varchar(16)   NOT NULL DEFAULT 'normal'
                    CHECK (priority IN ('low','normal','high','critical')),
                owner_id     uuid          NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                budget       numeric(14,2) CHECK (budget IS NULL OR budget >= 0),
                starts_on    date,
                due_on       date,
                tags         jsonb         NOT NULL DEFAULT '[]'::jsonb,
                version      integer       NOT NULL DEFAULT 1,
                created_by   uuid          REFERENCES users (id) ON DELETE SET NULL,
                created_at   timestamptz   NOT NULL DEFAULT now(),
                updated_at   timestamptz   NOT NULL DEFAULT now(),
                archived_at  timestamptz,
                search       tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('simple', coalesce(code, '')), 'A') ||
                    setweight(to_tsvector('simple', coalesce(name, '')), 'A') ||
                    setweight(to_tsvector('simple', coalesce(description, '')), 'B')
                ) STORED,
                CONSTRAINT projects_dates_chk CHECK (due_on IS NULL OR starts_on IS NULL OR due_on >= starts_on),
                CONSTRAINT projects_code_format CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{1,31}$'),
                CONSTRAINT projects_tags_array CHECK (jsonb_typeof(tags) = 'array')
            );
            CREATE UNIQUE INDEX projects_tenant_code_uq ON projects (tenant_id, code);
            CREATE INDEX projects_tenant_status_idx ON projects (tenant_id, status, created_at DESC);
            CREATE INDEX projects_owner_idx ON projects (owner_id);
            CREATE INDEX projects_search_idx ON projects USING gin (search);
            CREATE INDEX projects_tags_idx ON projects USING gin (tags jsonb_path_ops);
            CREATE TRIGGER projects_touch BEFORE UPDATE ON projects FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();

            -- Trigram index for fuzzy "contains" search on the name (only when pg_trgm can be used)
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm') THEN
                    EXECUTE 'CREATE INDEX projects_name_trgm_idx ON projects USING gin (lower(name) gin_trgm_ops)';
                ELSIF EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'pg_trgm') THEN
                    BEGIN
                        EXECUTE 'CREATE EXTENSION IF NOT EXISTS pg_trgm';
                        EXECUTE 'CREATE INDEX projects_name_trgm_idx ON projects USING gin (lower(name) gin_trgm_ops)';
                    EXCEPTION WHEN insufficient_privilege THEN
                        RAISE NOTICE 'pg_trgm not installable, skipping trigram index';
                    END;
                END IF;
            END
            $$;

            CREATE TABLE project_members (
                project_id  uuid         NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
                user_id     uuid         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                role        varchar(16)  NOT NULL DEFAULT 'member' CHECK (role IN ('owner','manager','member','viewer')),
                added_by    uuid,
                created_at  timestamptz  NOT NULL DEFAULT now(),
                PRIMARY KEY (project_id, user_id)
            );
            CREATE INDEX project_members_user_idx ON project_members (user_id);

            -- Read model maintained by the Kafka consumer group "projects-audit" (topic projects.events)
            CREATE TABLE project_activity (
                id           bigserial PRIMARY KEY,
                event_id     varchar(128) NOT NULL UNIQUE,
                project_id   uuid         NOT NULL,
                tenant_id    uuid,
                event_type   varchar(64)  NOT NULL,
                actor_id     uuid,
                summary      varchar(500) NOT NULL,
                payload      jsonb        NOT NULL DEFAULT '{}'::jsonb,
                occurred_at  timestamptz  NOT NULL,
                recorded_at  timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX project_activity_project_idx ON project_activity (project_id, occurred_at DESC);

            CREATE TABLE project_reports (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id     uuid         NOT NULL,
                job_id        uuid,
                status        varchar(16)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','running','done','failed')),
                filters       jsonb        NOT NULL DEFAULT '{}'::jsonb,
                path          varchar(1024),
                rows_count    integer,
                size_bytes    bigint,
                checksum_sha256 char(64),
                error         text,
                requested_by  uuid,
                created_at    timestamptz  NOT NULL DEFAULT now(),
                finished_at   timestamptz
            );
            CREATE INDEX project_reports_tenant_idx ON project_reports (tenant_id, created_at DESC);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS project_reports, project_activity, project_members, projects CASCADE;
            SQL);
    }
}
