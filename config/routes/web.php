<?php

declare(strict_types=1);

use App\Platform\Audit\ActivityLogMiddleware;
use App\Platform\FeatureFlags\FeatureRegistry;
use App\Shared\Infrastructure\Http\DomainErrorMiddleware;
use App\Web\Middleware\AuthContextMiddleware;
use App\Web\Middleware\LocaleMiddleware;
use App\Web\Middleware\RequireLogin;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;
use Yiisoft\Session\SessionMiddleware;

/**
 * End-user web application — area entry point: middleware + route discovery. Routes live next to controllers:
 *   src/Web/Home/routes.php                 public ("/")
 *   src/Web/Account/routes.php              signed-in users
 *   src/Web/Notifications/routes.php        signed-in users
 *   src/Modules/<Name>/Web/routes.php       signed-in users, wrapped in the module.<code> flag
 * Sign-in pages: src/Web/Auth/routes.php (config/routes/auth.php); health/metrics: src/Web/System/routes.php.
 */
$web = dirname(__DIR__, 2) . '/src/Web';
$modules = FeatureRegistry::loadRoutes('web', glob(dirname(__DIR__, 2) . '/src/Modules/*/Web/routes.php') ?: []);

return [
    Group::create()
        ->middleware(SessionMiddleware::class, CsrfTokenMiddleware::class, LocaleMiddleware::class, AuthContextMiddleware::class, DomainErrorMiddleware::class, ActivityLogMiddleware::class)
        ->routes(...[
            ...require $web . '/Home/routes.php',
            Group::create()
                ->middleware(RequireLogin::class)
                ->routes(...[
                    ...require $web . '/Account/routes.php',
                    ...require $web . '/Notifications/routes.php',
                    ...$modules,
                ]),
        ]),
];
