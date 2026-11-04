<?php

declare(strict_types=1);

use App\Api\V1\Action\Docs\DocsController;
use App\Api\V1\Action\FallbackAction;
use App\Api\V1\Http\JsonBadRequestHandler;
use App\Api\V1\Middleware\ContentTypeGuard;
use App\Api\V1\Middleware\CorsMiddleware;
use App\Api\V1\Middleware\RateLimitMiddleware;
use App\Api\V1\Middleware\RequireFeature;
use App\Shared\Infrastructure\Http\DomainErrorMiddleware;
use Yiisoft\Definitions\Reference;
use Yiisoft\Request\Body\RequestBodyParser;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/**
 * REST API v1: stateless (no session, no cookies, no CSRF). Resource files live in
 * config/routes/api-v1/*.php (loaded in name order) and inherit this pipeline:
 *
 *   JSON errors > api.v1 flag (404 when off) > CORS (api.cors_origins, preflight) > rate limit
 *   (X-RateLimit-*, 429) > JSON errors (inner, keeps rate/CORS headers) > 415 guard > JSON body parser
 *
 * Protected routes add BearerAuthentication + RequireApiPermission::for(permission, scope) themselves.
 * The trailing catch-all turns unmatched paths into JSON 404 / 405 (+Allow) inside the pipeline.
 */
$resources = \App\Platform\FeatureFlags\FeatureRegistry::loadRoutes('api', glob(__DIR__ . '/api-v1/*.php') ?: []);

return [
    Route::get('/api/docs')
        ->middleware(DomainErrorMiddleware::json(), RequireFeature::for('api.v1'), RequireFeature::for('api.docs'))
        ->action([DocsController::class, 'page'])
        ->name('api.docs'),

    Group::create('/api/v1')
        ->middleware(
            DomainErrorMiddleware::json(),
            RequireFeature::for('api.v1'),
            CorsMiddleware::class,
            RateLimitMiddleware::class,
            \App\Platform\Audit\ActivityLogMiddleware::class,
            DomainErrorMiddleware::json(),
            ContentTypeGuard::class,
            [
                'class' => RequestBodyParser::class,
                '__construct()' => ['badRequestHandler' => Reference::to(JsonBadRequestHandler::class)],
            ],
        )
        ->routes(...[
            ...$resources,
            Route::methods(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], '/{path:.*}')
                ->action(FallbackAction::class)
                ->name(FallbackAction::ROUTE),
        ]),
];
