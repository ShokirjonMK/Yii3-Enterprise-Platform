<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Access passwords for the API documentation (/api/docs, /api/v1/openapi.json). A superadmin issues one per
 * recipient in Admin → API Management → Docs access; the password is shown once and only its SHA-256 hash is
 * stored (it is a random 100-bit value, like personal tokens). Signed-in users with api.docs.view need no password.
 *
 * Author: ShokirjonMK
 */
final class M261105300000ApiDocsPasswords implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE api_docs_passwords (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                label         varchar(120) NOT NULL CHECK (length(btrim(label)) > 0),
                secret_hash   char(64)     NOT NULL UNIQUE,
                hint          varchar(8)   NOT NULL,
                expires_at    timestamptz  NOT NULL,
                revoked_at    timestamptz,
                revoked_by    uuid,
                last_used_at  timestamptz,
                use_count     integer      NOT NULL DEFAULT 0,
                created_at    timestamptz  NOT NULL DEFAULT now(),
                created_by    uuid
            );
            CREATE INDEX api_docs_passwords_active ON api_docs_passwords (expires_at) WHERE revoked_at IS NULL;
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP TABLE IF EXISTS api_docs_passwords');
    }
}
