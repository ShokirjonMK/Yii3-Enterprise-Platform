<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Admin UI in src/Modules/<Module>/Admin/: routes.php, thin controller and views/ (list with HTMX
 * filters/sort/pagination, form, detail).
 */
final class AdminUiGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'admin';
    }

    public static function getName(): string
    {
        return 'Admin UI';
    }

    public static function getDescription(): string
    {
        return 'Admin routes file, thin controller and views (list with HTMX filters/sort/pagination, form, detail) behind RequirePermission and the module feature flag.';
    }

    public static function requires(): array
    {
        return ['crud', 'rbac'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Admin/' . $m->module . 'Controller.php');
    }

    protected function files(ModuleSpec $m): array
    {
        $files = [
            $m->adminRouteFile() => 'routes.php',
            $m->modulePath('Admin/' . $m->module . 'Controller.php') => 'controller.php',
            $m->modulePath('Admin/views/index.php') => 'index.php',
            $m->modulePath('Admin/views/form.php') => 'form.php',
            $m->modulePath('Admin/views/view.php') => 'view.php',
            $m->modulePath('Admin/views/_fields.php') => '../_shared/fields.php',
        ];
        if ($m->has('report')) {
            $files[$m->modulePath('Admin/views/report.php')] = 'report.php';
        }
        return $files;
    }
}
