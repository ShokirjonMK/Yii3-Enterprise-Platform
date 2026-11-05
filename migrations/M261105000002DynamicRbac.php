<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Dynamic RBAC (Admin → 04 RBAC):
 *  - rbac_role_meta: extra attributes of roles created/edited in the admin panel (administrative flag, author),
 *  - rbac_link_overrides: parent→child links (role→permission / role→role) added or removed in the admin panel for
 *    catalog roles, so that `rbac:sync` applies "catalog ∪ add − remove" and never undoes panel edits.
 *
 * Author: ShokirjonMK
 */
final class M261105000002DynamicRbac implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE rbac_role_meta (
                name        varchar(126) PRIMARY KEY,
                is_admin    boolean      NOT NULL DEFAULT false,
                created_by  uuid,
                created_at  timestamptz  NOT NULL DEFAULT now(),
                updated_at  timestamptz  NOT NULL DEFAULT now()
            );
            COMMENT ON TABLE rbac_role_meta IS 'Admin-panel attributes of RBAC roles (administrative flag, author)';

            CREATE TABLE rbac_link_overrides (
                parent      varchar(126) NOT NULL,
                child       varchar(126) NOT NULL,
                op          varchar(6)   NOT NULL CHECK (op IN ('add', 'remove')),
                created_by  uuid,
                created_at  timestamptz  NOT NULL DEFAULT now(),
                PRIMARY KEY (parent, child)
            );
            CREATE INDEX rbac_link_overrides_child_idx ON rbac_link_overrides (child);
            COMMENT ON TABLE rbac_link_overrides IS 'Admin-panel edits of catalog role links, preserved by rbac:sync';
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS rbac_link_overrides;
            DROP TABLE IF EXISTS rbac_role_meta;
            SQL);
    }
}
