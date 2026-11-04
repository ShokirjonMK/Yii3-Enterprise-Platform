<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * PostgreSQL Entity / Repository: aggregate (+ lifecycle enum), validation rules, repository and the
 * schema migration (constraints, indexes, version column, tenant_id, feature flag), module manifest, DI file.
 */
final class EntityGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'entity';
    }

    public static function getName(): string
    {
        return 'PostgreSQL Entity / Repository';
    }

    public static function getDescription(): string
    {
        return 'Aggregate with invariants and lifecycle enum, repository (tenant scope, optimistic locking, search/sort whitelist) and the migration (constraints, indexes, version, tenant_id, feature flag).';
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Domain/' . $m->entity . '.php');
    }

    protected function files(ModuleSpec $m): array
    {
        $files = [
            $m->modulePath('module.php') => 'module.php',
            $m->modulePath('Domain/' . $m->entity . '.php') => 'entity.php',
            $m->modulePath('Domain/' . $m->entity . 'Rules.php') => '../_shared/rules.php',
            $m->modulePath('Infrastructure/' . $m->entity . 'Repository.php') => '../_shared/repository.php',
            $m->migrationPath() => 'migration.php',
            'config/common/di/' . $m->code . '.php' => 'di.php',
        ];
        if ($m->hasStatus()) {
            $files[$m->modulePath('Domain/' . $m->entity . 'Status.php')] = 'status.php';
        }
        return $files;
    }
}
