<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * REST API: config/routes/api-v1/<NN>-<module>.php (Bearer auth, scope + permission per route, feature flag)
 * and the controller with OpenAPI attributes (src/Api/V1/Action/<Module>/).
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
        return 'src/Api/V1/Action/' . $m->module . '/' . $m->module . 'Controller.php';
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->apiRouteFile() => 'routes.php',
            'src/Api/V1/Action/' . $m->module . '/' . $m->module . 'Controller.php' => 'controller.php',
        ];
    }
}
