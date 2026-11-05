<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Multi-method two-factor authentication:
 *  - user_mfa_methods: second factors other than the authenticator app (TOTP stays in users.mfa_secret_enc):
 *    telegram, email, sms, voice, eimzo, webauthn (passkeys / security keys). Targets are stored encrypted.
 *  - mfa_policies: per-role policy (required, allowed methods, remember-device days); '*' = everyone else.
 *  - mfa_trusted_devices: "remember this device" tokens (sha256 only).
 *
 * Author: ShokirjonMK
 */
final class M261105000001MfaMethods implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE user_mfa_methods (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type          varchar(16)  NOT NULL CHECK (type IN ('telegram','email','sms','voice','eimzo','webauthn')),
                label         varchar(120) NOT NULL DEFAULT '',
                target_enc    text,
                target_hint   varchar(120) NOT NULL DEFAULT '',
                lookup_hash   varchar(64),
                secret_enc    text,
                meta          jsonb NOT NULL DEFAULT '{}'::jsonb,
                sign_count    bigint NOT NULL DEFAULT 0,
                created_at    timestamptz NOT NULL DEFAULT now(),
                last_used_at  timestamptz
            );
            CREATE INDEX user_mfa_methods_user_idx ON user_mfa_methods (user_id);
            CREATE UNIQUE INDEX user_mfa_methods_one_per_type ON user_mfa_methods (user_id, type) WHERE type <> 'webauthn';
            CREATE UNIQUE INDEX user_mfa_methods_lookup ON user_mfa_methods (type, lookup_hash) WHERE lookup_hash IS NOT NULL;

            ALTER TABLE users ADD COLUMN mfa_preferred varchar(16);

            CREATE TABLE mfa_policies (
                subject        varchar(64) PRIMARY KEY,
                required       boolean NOT NULL DEFAULT false,
                allowed        jsonb   NOT NULL DEFAULT '[]'::jsonb,
                remember_days  integer NOT NULL DEFAULT 0 CHECK (remember_days BETWEEN 0 AND 90),
                updated_at     timestamptz NOT NULL DEFAULT now(),
                updated_by     uuid
            );
            INSERT INTO mfa_policies (subject, required, allowed, remember_days) VALUES
                ('*',              false, '["totp","webauthn","telegram","email","sms","voice","eimzo"]', 30),
                ('superadmin',     true,  '["totp","webauthn","eimzo","telegram"]', 0),
                ('platform_admin', true,  '["totp","webauthn","eimzo","telegram"]', 0),
                ('security_admin', true,  '["totp","webauthn","eimzo","telegram"]', 0),
                ('devops_sre',     true,  '["totp","webauthn","eimzo","telegram"]', 0);

            CREATE TABLE mfa_trusted_devices (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash    char(64) NOT NULL UNIQUE,
                user_agent    varchar(255) NOT NULL DEFAULT '',
                ip            inet,
                created_at    timestamptz NOT NULL DEFAULT now(),
                expires_at    timestamptz NOT NULL,
                last_used_at  timestamptz
            );
            CREATE INDEX mfa_trusted_devices_user_idx ON mfa_trusted_devices (user_id);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS mfa_trusted_devices;
            DROP TABLE IF EXISTS mfa_policies;
            ALTER TABLE users DROP COLUMN IF EXISTS mfa_preferred;
            DROP TABLE IF EXISTS user_mfa_methods;
            SQL);
    }
}
