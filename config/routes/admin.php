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
 * Platform Control Center (/admin). Each section keeps its routes in config/routes/admin/<section>.php;
 * every file returns a list of Route/Group objects that inherit this group's security middleware:
 * session → CSRF → locale → session registry/gates → login → admin network allowlist → admin.access → error mapping.
 */
$sections = [];
$files = glob(__DIR__ . '/admin/*.php') ?: [];
sort($files);
foreach ($files as $file) {
    $sections = [...$sections, ...require $file];
}

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
        )
        ->routes(...$sections),
];
