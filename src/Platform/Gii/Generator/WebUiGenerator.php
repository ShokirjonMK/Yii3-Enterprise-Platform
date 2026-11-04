<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Web UI: config/routes/web/<module>.php (inside the authenticated web group), controller
 * (src/Web/Action/<Module>/) and views (resources/views/web/<module>/).
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
        return 'src/Web/Action/' . $m->module . '/' . $m->module . 'Controller.php';
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            'config/routes/web/' . $m->slug . '.php' => 'routes.php',
            'src/Web/Action/' . $m->module . '/' . $m->module . 'Controller.php' => 'controller.php',
            'resources/views/web/' . $m->slug . '/index.php' => 'index.php',
            'resources/views/web/' . $m->slug . '/form.php' => 'form.php',
            'resources/views/web/' . $m->slug . '/view.php' => 'view.php',
            'resources/views/web/' . $m->slug . '/_fields.php' => '../_shared/fields.php',
        ];
    }
}
