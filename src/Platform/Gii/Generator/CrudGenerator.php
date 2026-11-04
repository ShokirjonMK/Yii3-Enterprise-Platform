<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * CRUD: application service (RBAC per use case, tenant isolation, optimistic locking, audit + outbox in one
 * transaction), repository and validation rules.
 */
final class CrudGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'crud';
    }

    public static function getName(): string
    {
        return 'CRUD';
    }

    public static function getDescription(): string
    {
        return 'Application service (RBAC, tenant isolation, optimistic locking, audit + outbox in one transaction), repository and validation rules.';
    }

    public static function requires(): array
    {
        return ['entity'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Application/' . $m->entity . 'Service.php');
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->modulePath('Domain/' . $m->entity . 'Rules.php') => '../_shared/rules.php',
            $m->modulePath('Infrastructure/' . $m->entity . 'Repository.php') => '../_shared/repository.php',
            $m->modulePath('Application/' . $m->entity . 'Service.php') => 'service.php',
        ];
    }
}
