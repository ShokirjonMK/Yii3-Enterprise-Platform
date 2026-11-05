<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * RBAC: src/Modules/<Module>/config/permissions.php (view/create/update/delete) extending existing roles;
 * applied with `./yii rbac:sync`.
 */
final class RbacGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'rbac';
    }

    public static function getName(): string
    {
        return 'RBAC';
    }

    public static function getDescription(): string
    {
        return 'Permission catalog src/Modules/<Module>/config/permissions.php extending the existing roles; apply with ./yii rbac:sync.';
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('config/permissions.php');
    }

    protected function files(ModuleSpec $m): array
    {
        return [$m->modulePath('config/permissions.php') => 'permissions.php'];
    }
}
