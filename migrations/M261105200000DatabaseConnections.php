<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Named database connections managed in Admin → Databases (superadmin). Connections declared in .env are not stored
 * here. The password is encrypted with the platform Encrypter (purpose "database-connection").
 *
 * Author: ShokirjonMK
 */
final class M261105200000DatabaseConnections implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE database_connections (
                name          varchar(64) PRIMARY KEY CHECK (name ~ '^[a-z][a-z0-9_]{1,63}$' AND name <> 'platform'),
                driver        varchar(16)  NOT NULL CHECK (driver IN ('pgsql','mysql','sqlite','sqlsrv','oci','mongodb','clickhouse')),
                host          varchar(255) NOT NULL DEFAULT '',
                port          integer      NOT NULL DEFAULT 0 CHECK (port BETWEEN 0 AND 65535),
                database      varchar(255) NOT NULL DEFAULT '',
                username      varchar(255) NOT NULL DEFAULT '',
                password_enc  text,
                options       jsonb        NOT NULL DEFAULT '{}'::jsonb,
                read_only     boolean      NOT NULL DEFAULT false,
                enabled       boolean      NOT NULL DEFAULT true,
                description   varchar(500) NOT NULL DEFAULT '',
                version       integer      NOT NULL DEFAULT 1,
                created_at    timestamptz  NOT NULL DEFAULT now(),
                created_by    uuid,
                updated_at    timestamptz  NOT NULL DEFAULT now(),
                updated_by    uuid
            );
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP TABLE IF EXISTS database_connections');
    }
}
