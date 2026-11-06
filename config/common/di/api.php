<?php

declare(strict_types=1);

use App\Api\V1\Middleware\RateLimitMiddleware;
use App\Api\V1\OpenApi\OpenApiGenerator;

/** @var array $params */

$root = dirname(__DIR__, 3);

return [
    OpenApiGenerator::class => [
        '__construct()' => [
            'sourceDir' => [$root . '/src/Api/V1', ...(glob($root . '/src/Modules/*/Api', GLOB_ONLYDIR) ?: [])],
            // outside public/: nginx would serve a file there to anybody, bypassing ApiDocsAccess
            'outputFile' => $root . '/runtime/api/openapi.json',
        ],
    ],

    RateLimitMiddleware::class => [
        '__construct()' => [
            'defaultLimit' => (int) ($params['platform']['security']['api']['rateLimitPerMinute'] ?? 600),
        ],
    ],
];
