<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Append-only, monthly range-partitioned audit log + partition maintenance helpers.
 *
 * Integrity: each row carries an HMAC (computed by the application with a key derived from APP_SECRET)
 * over its canonical content; UPDATE/DELETE are rejected by trigger. Old partitions are detached and
 * archived, never edited.
 */
final class M261104000003Audit implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            -- Generic helper: create monthly partitions [from current month - back, + ahead]
            CREATE OR REPLACE FUNCTION platform_ensure_monthly_partitions(parent regclass, months_back int, months_ahead int)
            RETURNS int AS $$
            DECLARE
                start_month date := date_trunc('month', now())::date - make_interval(months => months_back);
                i int;
                created int := 0;
                p_from date;
                p_to date;
                p_name text;
                base text := split_part(parent::text, '.', array_length(string_to_array(parent::text, '.'), 1));
            BEGIN
                FOR i IN 0 .. (months_back + months_ahead) LOOP
                    p_from := start_month + make_interval(months => i);
                    p_to := p_from + interval '1 month';
                    p_name := format('%s_p%s', base, to_char(p_from, 'YYYYMM'));
                    IF to_regclass(p_name) IS NULL THEN
                        EXECUTE format('CREATE TABLE %I PARTITION OF %s FOR VALUES FROM (%L) TO (%L)', p_name, parent, p_from, p_to);
                        created := created + 1;
                    END IF;
                END LOOP;
                RETURN created;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TABLE audit_log (
                id              bigint GENERATED ALWAYS AS IDENTITY,
                occurred_at     timestamptz  NOT NULL DEFAULT now(),
                category        varchar(32)  NOT NULL DEFAULT 'data',
                action          varchar(128) NOT NULL,
                result          varchar(16)  NOT NULL DEFAULT 'success' CHECK (result IN ('success','denied','failure')),
                severity        varchar(16)  NOT NULL DEFAULT 'info' CHECK (severity IN ('debug','info','notice','warning','critical')),
                actor_id        uuid,
                actor_name      varchar(255),
                actor_type      varchar(16)  NOT NULL DEFAULT 'user',
                tenant_id       uuid,
                entity_type     varchar(96),
                entity_id       varchar(96),
                ip              inet,
                user_agent      varchar(500),
                correlation_id  varchar(128),
                before          jsonb,
                after           jsonb,
                metadata        jsonb        NOT NULL DEFAULT '{}'::jsonb,
                row_hmac        char(64)     NOT NULL,
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at);

            CREATE TABLE audit_log_default PARTITION OF audit_log DEFAULT;
            SELECT platform_ensure_monthly_partitions('audit_log', 1, 3);

            CREATE INDEX audit_log_occurred_idx ON audit_log (occurred_at DESC);
            CREATE INDEX audit_log_actor_idx ON audit_log (actor_id, occurred_at DESC);
            CREATE INDEX audit_log_entity_idx ON audit_log (entity_type, entity_id, occurred_at DESC);
            CREATE INDEX audit_log_action_idx ON audit_log (action, occurred_at DESC);
            CREATE INDEX audit_log_tenant_idx ON audit_log (tenant_id, occurred_at DESC);
            CREATE INDEX audit_log_category_idx ON audit_log (category, result, occurred_at DESC);

            CREATE OR REPLACE FUNCTION platform_audit_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only (% denied)', TG_OP USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_log_no_update BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION platform_audit_immutable();
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS audit_log CASCADE;
            DROP FUNCTION IF EXISTS platform_audit_immutable();
            DROP FUNCTION IF EXISTS platform_ensure_monthly_partitions(regclass, int, int);
            SQL);
    }
}
