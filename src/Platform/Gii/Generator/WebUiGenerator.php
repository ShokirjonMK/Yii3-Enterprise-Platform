<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Web UI in src/Modules/<Module>/Web/: routes.php (inside the authenticated web group), controller and views/.
 */
final class WebUiGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'web';
    }

    public static function getName(): string
    {
        return 'Web UI';
    }

    public static function getDescription(): string
    {
        return 'End-user routes (authenticated web group), controller and views (list, detail, create/edit form).';
    }

    public static function requires(): array
    {
        return ['crud', 'rbac'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Web/' . $m->module . 'Controller.php');
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->modulePath('Web/routes.php') => 'routes.php',
            $m->modulePath('Web/' . $m->module . 'Controller.php') => 'controller.php',
            $m->modulePath('Web/views/index.php') => 'index.php',
            $m->modulePath('Web/views/form.php') => 'form.php',
            $m->modulePath('Web/views/view.php') => 'view.php',
            $m->modulePath('Web/views/_fields.php') => '../_shared/fields.php',
        ];
    }
}
