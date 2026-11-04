<?php

declare(strict_types=1);

/**
 * Template: schema migration (table with constraints/indexes/version/tenant_id, feature flag, module registry row).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$t = $m->code;
$cols = [
    'id           uuid PRIMARY KEY DEFAULT gen_random_uuid()',
    'tenant_id    uuid NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT',
];
foreach ($m->fields as $f) {
    $cols[] = str_pad($f->name, 12) . ' ' . $f->sqlColumn();
}
array_push(
    $cols,
    'version      integer NOT NULL DEFAULT 1',
    'created_by   uuid REFERENCES users (id) ON DELETE SET NULL',
    'created_at   timestamptz NOT NULL DEFAULT now()',
    'updated_at   timestamptz NOT NULL DEFAULT now()',
    'deleted_at   timestamptz',
    "CONSTRAINT {$t}_version_chk CHECK (version > 0)",
);
foreach ($m->fields as $f) {
    foreach ($f->sqlChecks($t) as $name => $expr) {
        $cols[] = "CONSTRAINT $name CHECK ($expr)";
    }
}
$idx = [
    "CREATE INDEX {$t}_tenant_created_idx ON $t (tenant_id, created_at DESC) WHERE deleted_at IS NULL;",
];
foreach ($m->fields as $f) {
    if ($f->unique) {
        $idx[] = "CREATE UNIQUE INDEX {$t}_tenant_{$f->name}_uq ON $t (tenant_id, {$f->name}) WHERE deleted_at IS NULL;";
    } elseif ($f->type === 'enum' || $f->index) {
        $idx[] = "CREATE INDEX {$t}_tenant_{$f->name}_idx ON $t (tenant_id, {$f->name}) WHERE deleted_at IS NULL;";
    } elseif ($f->type === 'user') {
        $idx[] = "CREATE INDEX {$t}_{$f->name}_idx ON $t ({$f->name});";
    }
}
$idx[] = "CREATE INDEX {$t}_deleted_idx ON $t (deleted_at) WHERE deleted_at IS NOT NULL;";
$idx[] = "CREATE TRIGGER {$t}_touch BEFORE UPDATE ON $t FOR EACH ROW EXECUTE FUNCTION platform_touch_updated_at();";

$indent = static fn(array $lines, int $n): string => implode("\n", array_map(static fn($l) => str_repeat(' ', $n) . $l, $lines));

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * {{label}} module schema: table "{{table}}" (uuid PK, tenant_id, CHECK constraints, partial indexes,
 * optimistic-lock version, soft delete, updated_at trigger), feature flag {{flag}} and the module registry row.
 */
final class __CLASS__ implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            CREATE TABLE {{table}} (
__COLUMNS__
            );
__INDEXES__

            INSERT INTO feature_flags (code, name, description, enabled, status)
            VALUES ('{{flag}}', '{{label}} module', 'Generated business module (Enterprise Gii).', true, 'enabled')
            ON CONFLICT (code) DO NOTHING;
            INSERT INTO business_modules (code, name, version, enabled) VALUES ('{{code}}', '{{label}}', '1.0.0', true)
            ON CONFLICT (code) DO NOTHING;
            UPDATE config_revision SET revision = revision + 1, changed_at = now() WHERE id = 1;
            SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        \App\Shared\Infrastructure\Database\SqlScript::run($b, <<<'SQL'
            DROP TABLE IF EXISTS {{table}} CASCADE;
            DELETE FROM business_modules WHERE code = '{{code}}';
            DELETE FROM feature_flags WHERE code = '{{flag}}';
            UPDATE config_revision SET revision = revision + 1, changed_at = now() WHERE id = 1;
            SQL);
    }
}

PHP, [
    '__CLASS__' => $m->migrationClass(),
    '__COLUMNS__' => $indent(array_map(static fn($c, $i) => $c . ($i < count($cols) - 1 ? ',' : ''), $cols, array_keys($cols)), 16),
    '__INDEXES__' => $indent($idx, 12),
]);
