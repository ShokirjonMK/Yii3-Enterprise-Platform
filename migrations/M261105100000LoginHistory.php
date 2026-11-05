<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Sign-in history: login_attempts becomes a per-user history (Account → Login history for the user, Admin → Users →
 * Login history for administrators). Adds the account, tenant, device (user agent) and the second factor used;
 * existing rows are linked to their account by username / e-mail.
 *
 * Author: ShokirjonMK
 */
final class M261105100000LoginHistory implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            ALTER TABLE login_attempts
                ADD COLUMN user_id    uuid REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN tenant_id  uuid,
                ADD COLUMN user_agent varchar(500),
                ADD COLUMN mfa_method varchar(32);
            UPDATE login_attempts a SET user_id = u.id, tenant_id = u.tenant_id
              FROM users u
             WHERE a.user_id IS NULL AND (lower(u.username) = lower(a.username) OR lower(u.email) = lower(a.username));
            CREATE INDEX login_attempts_user_id_idx ON login_attempts (user_id, created_at DESC);
            CREATE INDEX login_attempts_tenant_idx ON login_attempts (tenant_id, created_at DESC);
            CREATE INDEX login_attempts_created_idx ON login_attempts (created_at DESC);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP INDEX IF EXISTS login_attempts_created_idx;
            DROP INDEX IF EXISTS login_attempts_tenant_idx;
            DROP INDEX IF EXISTS login_attempts_user_id_idx;
            ALTER TABLE login_attempts DROP COLUMN IF EXISTS mfa_method, DROP COLUMN IF EXISTS user_agent,
                DROP COLUMN IF EXISTS tenant_id, DROP COLUMN IF EXISTS user_id;
            SQL);
    }
}
