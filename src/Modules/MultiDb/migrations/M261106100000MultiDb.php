<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Multi-DB demo module registration in the platform database (feature flag module.multidb + module registry row).
 * The demo tables themselves live in the other connections and are created by `./yii multidb:install`
 * (or Admin → Multi-DB demo → Install) — the platform migrator only manages the platform database.
 */
final class M261106100000MultiDb implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            INSERT INTO feature_flags (code, name, description, enabled, status)
            VALUES ('module.multidb', 'Multi-DB demo module', 'CRUD over MariaDB, PostgreSQL, MongoDB and ClickHouse connections.', true, 'enabled')
            ON CONFLICT (code) DO NOTHING;
            INSERT INTO business_modules (code, name, version, enabled) VALUES ('multidb', 'Multi-DB demo', '1.0.0', true)
            ON CONFLICT (code) DO NOTHING;
            UPDATE config_revision SET revision = revision + 1, changed_at = now() WHERE id = 1;
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DELETE FROM business_modules WHERE code = 'multidb';
            DELETE FROM feature_flags WHERE code = 'module.multidb';
            UPDATE config_revision SET revision = revision + 1, changed_at = now() WHERE id = 1;
            SQL);
    }
}
