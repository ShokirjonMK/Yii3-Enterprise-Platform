<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Design themes (Admin → System Settings → Appearance):
 *  - appearance_themes: uploaded theme packs (built-in packs live in themes/<code>/ and are not stored here).
 *    CSS is stored in the database so every pod/instance serves the same file.
 *  - appearance_choice: active theme per scope — global (scope_id ''), tenant (tenant uuid) or user (user uuid).
 *    The global row also carries allow_user_choice.
 *
 * Author: ShokirjonMK
 */
final class M261105000003Appearance implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE appearance_themes (
                code         varchar(40) PRIMARY KEY CHECK (code ~ '^[a-z0-9][a-z0-9-]{1,39}$'),
                manifest     jsonb NOT NULL,
                css          text  NOT NULL,
                hash         char(16) NOT NULL,
                created_by   uuid,
                created_at   timestamptz NOT NULL DEFAULT now()
            );
            CREATE TABLE appearance_choice (
                scope              varchar(8)  NOT NULL CHECK (scope IN ('global','tenant','user')),
                scope_id           varchar(64) NOT NULL DEFAULT '',
                theme              varchar(40) NOT NULL,
                allow_user_choice  boolean NOT NULL DEFAULT true,
                updated_at         timestamptz NOT NULL DEFAULT now(),
                updated_by         uuid,
                PRIMARY KEY (scope, scope_id)
            );
            INSERT INTO appearance_choice (scope, scope_id, theme, allow_user_choice) VALUES ('global', '', 'default', true);
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS appearance_choice;
            DROP TABLE IF EXISTS appearance_themes;
            SQL);
    }
}
