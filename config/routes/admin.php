<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Http\DomainErrorMiddleware;
use App\Web\Middleware\AuthContextMiddleware;
use App\Web\Middleware\LocaleMiddleware;
use App\Web\Middleware\RequireLogin;
use App\Web\Middleware\RequirePermission;
use App\Admin\Middleware\AdminNetworkMiddleware;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Router\Group;
use Yiisoft\Session\SessionMiddleware;

/**
 * Platform Control Center (/admin) — area entry point: security middleware + route discovery.
 * Routes live next to their controllers:
 *   src/Admin/<Section>/routes.php        platform sections (Users, Kafka, Rbac, …)
 *   src/Modules/<Name>/Admin/routes.php   business modules (wrapped in the module.<code> flag)
 * Every file returns a list of Route/Group objects that inherit this group's middleware:
 * session → CSRF → locale → session registry/gates → login → admin network allowlist → admin.access → error mapping.
 */
// Optional sections are wrapped in RequireFeature groups (config/common/features.php): disabled → 404
$src = dirname(__DIR__, 2) . '/src';
$sections = \App\Platform\FeatureFlags\FeatureRegistry::loadRoutes('admin', [
    ...(glob($src . '/Admin/*/routes.php') ?: []),
    ...(glob($src . '/Modules/*/Admin/routes.php') ?: []),
]);

return [
    Group::create('/admin')
        ->middleware(
            SessionMiddleware::class,
            CsrfTokenMiddleware::class,
            LocaleMiddleware::class,
            AuthContextMiddleware::class,
            RequireLogin::class,
            AdminNetworkMiddleware::class,
            DomainErrorMiddleware::class,
            RequirePermission::for('admin.access'),
            \App\Platform\Audit\ActivityLogMiddleware::class,
        )
        ->routes(...$sections),
];
