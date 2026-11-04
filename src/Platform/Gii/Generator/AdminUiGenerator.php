<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Admin UI: config/routes/admin/<NN>-<module>.php, thin controller (src/Admin/Action/<Module>/) and views
 * (resources/views/admin/<module>/: list with HTMX filters/sort/pagination, form, detail).
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
        return 'src/Admin/Action/' . $m->module . '/' . $m->module . 'Controller.php';
    }

    protected function files(ModuleSpec $m): array
    {
        $files = [
            $m->adminRouteFile() => 'routes.php',
            'src/Admin/Action/' . $m->module . '/' . $m->module . 'Controller.php' => 'controller.php',
            'resources/views/admin/' . $m->slug . '/index.php' => 'index.php',
            'resources/views/admin/' . $m->slug . '/form.php' => 'form.php',
            'resources/views/admin/' . $m->slug . '/view.php' => 'view.php',
            'resources/views/admin/' . $m->slug . '/_fields.php' => '../_shared/fields.php',
        ];
        if ($m->has('report')) {
            $files['resources/views/admin/' . $m->slug . '/report.php'] = 'report.php';
        }
        return $files;
    }
}
