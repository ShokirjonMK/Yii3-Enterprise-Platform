<?php

declare(strict_types=1);

use App\Environment;
use App\Shared\Infrastructure\Http\ClientIp;
use App\Shared\Infrastructure\Http\CorrelationIdMiddleware;
use App\Shared\Infrastructure\Http\SecurityHeadersMiddleware;
use App\Platform\Monitoring\Http\MetricsMiddleware;
use App\Web\Action\System\NotFoundHandler;
use App\Web\Middleware\LocaleMiddleware;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Input\Http\HydratorAttributeParametersResolver;
use Yiisoft\Input\Http\RequestInputParametersResolver;
use Yiisoft\Middleware\Dispatcher\CompositeParametersResolver;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Middleware\Dispatcher\ParametersResolverInterface;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\RequestProvider\RequestCatcherMiddleware;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\Http\Application;

/** @var array $params */

$platform = $params['platform'];

/*
 * Global pipeline (all requests). Session/CSRF/auth are attached per route group
 * (config/routes/*.php) so that the JSON API stays stateless and cookie-free.
 */
return [
    Application::class => [
        '__construct()' => [
            'dispatcher' => DynamicReference::to([
                'class' => MiddlewareDispatcher::class,
                'withMiddlewares()' => [
                    [
                        ErrorCatcher::class,
                        SecurityHeadersMiddleware::class,
                        CorrelationIdMiddleware::class,
                        App\Platform\Monitoring\Tracing\TracingMiddleware::class,
                        MetricsMiddleware::class,
                        RequestCatcherMiddleware::class,
                        Router::class,
                    ],
                ],
            ]),
            'fallbackHandler' => Reference::to(NotFoundHandler::class),
        ],
    ],

    ParametersResolverInterface::class => [
        'class' => CompositeParametersResolver::class,
        '__construct()' => [
            Reference::to(HydratorAttributeParametersResolver::class),
            Reference::to(RequestInputParametersResolver::class),
        ],
    ],

    CurrentUser::class => [
        'withSession()' => [Reference::to(SessionInterface::class)],
        'withAccessChecker()' => [Reference::to(ManagerInterface::class)],
        'withAuthTimeout()' => [$platform['security']['session']['idleTimeout']],
        'withAbsoluteAuthTimeout()' => [$platform['security']['session']['absoluteTimeout']],
        'reset' => function () {
            $this->clear();
        },
    ],

    SecurityHeadersMiddleware::class => [
        '__construct()' => [
            'csp' => $platform['security']['csp'],
            'hsts' => str_starts_with((string) $platform['url'], 'https://'),
        ],
    ],

    ClientIp::class => [
        '__construct()' => ['trustedProxies' => $platform['trustedProxies']],
    ],

    LocaleMiddleware::class => [
        '__construct()' => [
            'supported' => array_keys($platform['locales']),
            'default' => $platform['defaultLocale'],
        ],
    ],

    RequireRecentAuth::class => [
        '__construct()' => ['maxAgeSeconds' => $platform['security']['session']['reauthWindow']],
    ],
];
