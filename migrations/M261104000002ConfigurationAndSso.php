<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Typed settings with versioning, feature flags, SSO providers, installed business modules.
 */
final class M261104000002ConfigurationAndSso implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            -- Monotonic configuration revision. Every settings/flag change bumps it; app instances compare
            -- their cached revision with this value (fallback when the Redis invalidation message is missed).
            CREATE TABLE config_revision (
                id        smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
                revision  bigint      NOT NULL DEFAULT 1,
                changed_at timestamptz NOT NULL DEFAULT now()
            );
            INSERT INTO config_revision (id, revision) VALUES (1, 1);

            CREATE TABLE settings (
                id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                key         varchar(128) NOT NULL,
                scope       varchar(16)  NOT NULL DEFAULT 'global' CHECK (scope IN ('global','tenant','user')),
                scope_id    uuid,
                value       jsonb        NOT NULL,
                is_secret   boolean      NOT NULL DEFAULT false,
                version     integer      NOT NULL DEFAULT 1,
                updated_by  uuid         REFERENCES users (id) ON DELETE SET NULL,
                updated_at  timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT settings_scope_id CHECK ((scope = 'global') = (scope_id IS NULL))
            );
            CREATE UNIQUE INDEX settings_key_scope_uq ON settings (key, scope, COALESCE(scope_id, '00000000-0000-0000-0000-000000000000'::uuid));

            CREATE TABLE setting_versions (
                id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                key         varchar(128) NOT NULL,
                scope       varchar(16)  NOT NULL,
                scope_id    uuid,
                value       jsonb        NOT NULL,
                version     integer      NOT NULL,
                changed_by  uuid         REFERENCES users (id) ON DELETE SET NULL,
                comment     varchar(500),
                changed_at  timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX setting_versions_key_idx ON setting_versions (key, scope, scope_id, version DESC);

            CREATE TABLE feature_flags (
                id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code                  varchar(96)  NOT NULL UNIQUE CHECK (code ~ '^[a-z0-9][a-z0-9_.-]{1,95}$'),
                name                  varchar(255) NOT NULL,
                description           text         NOT NULL DEFAULT '',
                enabled               boolean      NOT NULL DEFAULT false,
                status                varchar(16)  NOT NULL DEFAULT 'unconfigured'
                    CHECK (status IN ('enabled','disabled','unconfigured','healthy','degraded','failed','maintenance')),
                rollout_percentage    smallint     NOT NULL DEFAULT 100 CHECK (rollout_percentage BETWEEN 0 AND 100),
                tenant_allowlist      jsonb        NOT NULL DEFAULT '[]'::jsonb,
                dependencies          jsonb        NOT NULL DEFAULT '[]'::jsonb,
                is_critical           boolean      NOT NULL DEFAULT false,
                scheduled_enable_at   timestamptz,
                scheduled_disable_at  timestamptz,
                version               integer      NOT NULL DEFAULT 1,
                updated_by            uuid         REFERENCES users (id) ON DELETE SET NULL,
                created_at            timestamptz  NOT NULL DEFAULT now(),
                updated_at            timestamptz  NOT NULL DEFAULT now()
            );
            CREATE TRIGGER feature_flags_touch BEFORE UPDATE ON feature_flags FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();

            CREATE TABLE feature_flag_overrides (
                flag_id    uuid    NOT NULL REFERENCES feature_flags (id) ON DELETE CASCADE,
                tenant_id  uuid    NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                enabled    boolean NOT NULL,
                PRIMARY KEY (flag_id, tenant_id)
            );

            CREATE TABLE sso_providers (
                id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code               varchar(64)  NOT NULL UNIQUE CHECK (code ~ '^[a-z0-9][a-z0-9_-]{1,63}$'),
                type               varchar(16)  NOT NULL CHECK (type IN ('oidc','oneid','oauth2')),
                name               varchar(255) NOT NULL,
                enabled            boolean      NOT NULL DEFAULT false,
                config             jsonb        NOT NULL DEFAULT '{}'::jsonb,
                secret_enc         text,
                redirect_allowlist jsonb        NOT NULL DEFAULT '[]'::jsonb,
                auto_provision     boolean      NOT NULL DEFAULT false,
                default_role       varchar(64)  NOT NULL DEFAULT 'user',
                tenant_id          uuid         REFERENCES tenants (id) ON DELETE CASCADE,
                status             varchar(16)  NOT NULL DEFAULT 'unconfigured'
                    CHECK (status IN ('unconfigured','healthy','degraded','failed','maintenance')),
                last_health_at     timestamptz,
                last_health_error  text,
                sort_order         smallint     NOT NULL DEFAULT 0,
                version            integer      NOT NULL DEFAULT 1,
                created_at         timestamptz  NOT NULL DEFAULT now(),
                updated_at         timestamptz  NOT NULL DEFAULT now()
            );
            CREATE TRIGGER sso_providers_touch BEFORE UPDATE ON sso_providers FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();

            CREATE TABLE business_modules (
                code          varchar(64)  PRIMARY KEY,
                name          varchar(255) NOT NULL,
                version       varchar(32)  NOT NULL,
                enabled       boolean      NOT NULL DEFAULT true,
                settings      jsonb        NOT NULL DEFAULT '{}'::jsonb,
                installed_at  timestamptz  NOT NULL DEFAULT now(),
                updated_at    timestamptz  NOT NULL DEFAULT now()
            );
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, 'DROP TABLE IF EXISTS business_modules, sso_providers, feature_flag_overrides, feature_flags, setting_versions, settings, config_revision CASCADE');
    }
}
