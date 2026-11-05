<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * REST API: src/Modules/<Module>/Api/routes.php (Bearer auth, scope + permission per route, feature flag)
 * and the controller with OpenAPI attributes (src/Modules/<Module>/Api/).
 */
final class RestApiGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'api';
    }

    public static function getName(): string
    {
        return 'REST API';
    }

    public static function getDescription(): string
    {
        return 'API v1 routes (Bearer auth, scope + permission per route, feature flag) and a controller with OpenAPI attributes, ETag/If-Match locking and the standard error shape.';
    }

    public static function requires(): array
    {
        return ['crud', 'rbac'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Api/' . $m->module . 'Controller.php');
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->apiRouteFile() => 'routes.php',
            $m->modulePath('Api/' . $m->module . 'Controller.php') => 'controller.php',
        ];
    }
}
