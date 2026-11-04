<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Tenancy, organizations, users, external identities, sessions, API tokens, login throttling.
 * UUID primary keys use the built-in gen_random_uuid() (PostgreSQL 13+), no extension required.
 */
final class M261104000001CoreIdentity implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE OR REPLACE FUNCTION platform_touch_updated_at() RETURNS trigger AS $$
            BEGIN
                NEW.updated_at := now();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TABLE tenants (
                id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code        varchar(64)  NOT NULL,
                name        varchar(255) NOT NULL,
                status      varchar(16)  NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','archived')),
                domain      varchar(255),
                settings    jsonb        NOT NULL DEFAULT '{}'::jsonb,
                created_at  timestamptz  NOT NULL DEFAULT now(),
                updated_at  timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT tenants_code_format CHECK (code ~ '^[a-z0-9][a-z0-9_-]{1,63}$')
            );
            CREATE UNIQUE INDEX tenants_code_uq ON tenants (code);
            CREATE UNIQUE INDEX tenants_domain_uq ON tenants (lower(domain)) WHERE domain IS NOT NULL;
            CREATE TRIGGER tenants_touch BEFORE UPDATE ON tenants FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();

            CREATE TABLE organizations (
                id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id   uuid         NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                parent_id   uuid         REFERENCES organizations (id) ON DELETE RESTRICT,
                code        varchar(64)  NOT NULL,
                name        varchar(255) NOT NULL,
                inn         varchar(9)   CHECK (inn IS NULL OR inn ~ '^[0-9]{9}$'),
                type        varchar(32)  NOT NULL DEFAULT 'department',
                status      varchar(16)  NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive','archived')),
                created_at  timestamptz  NOT NULL DEFAULT now(),
                updated_at  timestamptz  NOT NULL DEFAULT now(),
                CONSTRAINT organizations_no_self_parent CHECK (parent_id IS NULL OR parent_id <> id)
            );
            CREATE UNIQUE INDEX organizations_tenant_code_uq ON organizations (tenant_id, code);
            CREATE INDEX organizations_parent_idx ON organizations (parent_id);
            CREATE TRIGGER organizations_touch BEFORE UPDATE ON organizations FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();

            CREATE TABLE users (
                id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id              uuid         REFERENCES tenants (id) ON DELETE RESTRICT,
                organization_id        uuid         REFERENCES organizations (id) ON DELETE SET NULL,
                username               varchar(64)  NOT NULL,
                email                  varchar(254),
                phone                  varchar(32),
                full_name              varchar(255) NOT NULL DEFAULT '',
                password_hash          varchar(255),
                status                 varchar(16)  NOT NULL DEFAULT 'active' CHECK (status IN ('active','blocked','pending','archived')),
                auth_policy            varchar(16)  NOT NULL DEFAULT 'any' CHECK (auth_policy IN ('any','local_only','sso_only')),
                must_change_password   boolean      NOT NULL DEFAULT false,
                password_changed_at    timestamptz,
                mfa_enabled            boolean      NOT NULL DEFAULT false,
                mfa_required           boolean      NOT NULL DEFAULT false,
                mfa_secret_enc         text,
                mfa_recovery_codes     jsonb        NOT NULL DEFAULT '[]'::jsonb,
                mfa_last_used_step     bigint,
                failed_logins          integer      NOT NULL DEFAULT 0,
                locked_until           timestamptz,
                last_login_at          timestamptz,
                last_login_ip          inet,
                locale                 varchar(8)   NOT NULL DEFAULT 'uz',
                timezone               varchar(64)  NOT NULL DEFAULT 'Asia/Tashkent',
                created_at             timestamptz  NOT NULL DEFAULT now(),
                updated_at             timestamptz  NOT NULL DEFAULT now(),
                deleted_at             timestamptz,
                version                integer      NOT NULL DEFAULT 1,
                CONSTRAINT users_username_format CHECK (username ~ '^[A-Za-z0-9._@-]{3,64}$'),
                CONSTRAINT users_sso_only_no_password CHECK (auth_policy <> 'sso_only' OR password_hash IS NULL)
            );
            CREATE UNIQUE INDEX users_username_uq ON users (lower(username)) WHERE deleted_at IS NULL;
            CREATE UNIQUE INDEX users_email_uq ON users (lower(email)) WHERE email IS NOT NULL AND deleted_at IS NULL;
            CREATE INDEX users_tenant_idx ON users (tenant_id, status);
            CREATE INDEX users_org_idx ON users (organization_id);
            CREATE TRIGGER users_touch BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();

            CREATE TABLE user_password_history (
                id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                user_id     uuid         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                hash        varchar(255) NOT NULL,
                created_at  timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX user_password_history_user_idx ON user_password_history (user_id, created_at DESC);

            CREATE TABLE user_identities (
                id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id        uuid         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                provider_code  varchar(64)  NOT NULL,
                issuer         varchar(512) NOT NULL,
                subject        varchar(255) NOT NULL,
                email          varchar(254),
                claims         jsonb        NOT NULL DEFAULT '{}'::jsonb,
                linked_at      timestamptz  NOT NULL DEFAULT now(),
                last_used_at   timestamptz
            );
            CREATE UNIQUE INDEX user_identities_issuer_subject_uq ON user_identities (issuer, subject);
            CREATE INDEX user_identities_user_idx ON user_identities (user_id);

            CREATE TABLE user_sessions (
                id               char(64)     PRIMARY KEY,
                user_id          uuid         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                auth_method      varchar(32)  NOT NULL,
                ip               inet,
                user_agent       varchar(500),
                created_at       timestamptz  NOT NULL DEFAULT now(),
                last_seen_at     timestamptz  NOT NULL DEFAULT now(),
                expires_at       timestamptz  NOT NULL,
                mfa_verified_at  timestamptz,
                reauth_at        timestamptz,
                revoked_at       timestamptz,
                revoked_reason   varchar(255)
            );
            CREATE INDEX user_sessions_user_idx ON user_sessions (user_id, revoked_at);
            CREATE INDEX user_sessions_expiry_idx ON user_sessions (expires_at);

            CREATE TABLE api_tokens (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id       uuid         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                name          varchar(128) NOT NULL DEFAULT 'api',
                type          varchar(16)  NOT NULL CHECK (type IN ('access','refresh','personal')),
                token_hash    char(64)     NOT NULL,
                family_id     uuid         NOT NULL DEFAULT gen_random_uuid(),
                scopes        jsonb        NOT NULL DEFAULT '[]'::jsonb,
                expires_at    timestamptz  NOT NULL,
                last_used_at  timestamptz,
                last_used_ip  inet,
                revoked_at    timestamptz,
                replaced_by   uuid,
                created_at    timestamptz  NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX api_tokens_hash_uq ON api_tokens (token_hash);
            CREATE INDEX api_tokens_user_idx ON api_tokens (user_id, type);
            CREATE INDEX api_tokens_family_idx ON api_tokens (family_id);

            CREATE TABLE login_attempts (
                id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                username    varchar(254),
                ip          inet,
                success     boolean      NOT NULL,
                reason      varchar(64),
                method      varchar(32)  NOT NULL DEFAULT 'password',
                created_at  timestamptz  NOT NULL DEFAULT now()
            );
            CREATE INDEX login_attempts_user_idx ON login_attempts (lower(username), created_at DESC);
            CREATE INDEX login_attempts_ip_idx ON login_attempts (ip, created_at DESC);

            CREATE TABLE bootstrap_tokens (
                id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                purpose     varchar(32)  NOT NULL,
                user_id     uuid         REFERENCES users (id) ON DELETE CASCADE,
                token_hash  char(64)     NOT NULL UNIQUE,
                expires_at  timestamptz  NOT NULL,
                used_at     timestamptz,
                created_at  timestamptz  NOT NULL DEFAULT now()
            );

            CREATE TABLE api_clients (
                id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                tenant_id              uuid         REFERENCES tenants (id) ON DELETE CASCADE,
                service_user_id        uuid         REFERENCES users (id) ON DELETE SET NULL,
                name                   varchar(128) NOT NULL,
                client_id              varchar(64)  NOT NULL UNIQUE,
                secret_hash            varchar(255) NOT NULL,
                scopes                 jsonb        NOT NULL DEFAULT '[]'::jsonb,
                allowed_ips            jsonb        NOT NULL DEFAULT '[]'::jsonb,
                rate_limit_per_minute  integer      NOT NULL DEFAULT 600 CHECK (rate_limit_per_minute BETWEEN 1 AND 100000),
                enabled                boolean      NOT NULL DEFAULT true,
                expires_at             timestamptz,
                last_used_at           timestamptz,
                created_at             timestamptz  NOT NULL DEFAULT now(),
                updated_at             timestamptz  NOT NULL DEFAULT now()
            );
            CREATE TRIGGER api_clients_touch BEFORE UPDATE ON api_clients FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS api_clients, bootstrap_tokens, login_attempts, api_tokens, user_sessions,
                user_identities, user_password_history, users, organizations, tenants CASCADE;
            DROP FUNCTION IF EXISTS platform_touch_updated_at();
            SQL);
    }
}
