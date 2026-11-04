<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * RBAC: config/common/permissions/<module>.php (view/create/update/delete) extending existing roles;
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
        return 'Permission catalog config/common/permissions/<module>.php extending the existing roles; apply with ./yii rbac:sync.';
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return 'config/common/permissions/' . $m->code . '.php';
    }

    protected function files(ModuleSpec $m): array
    {
        return ['config/common/permissions/' . $m->code . '.php' => 'permissions.php'];
    }
}
