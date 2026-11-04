<?php

declare(strict_types=1);

use App\Platform\Access\RbacCatalog;
use App\Platform\Settings\SettingsService;
use App\Platform\Identity\Application\ApiTokenService;
use App\Platform\Identity\Application\AuthenticationService;
use App\Platform\Identity\Application\LoginThrottle;
use App\Platform\Identity\Application\MfaService;
use App\Platform\Identity\Application\PasswordPolicy;
use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Application\UserService;
use App\Platform\Identity\Infrastructure\IdentityRepository;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Auth\IdentityWithTokenRepositoryInterface;

/** @var array $params */

$security = $params['platform']['security'];

return [
    RbacCatalog::class => [
        '__construct()' => ['directory' => dirname(__DIR__) . '/permissions'],
    ],

    SettingsService::class => [
        '__construct()' => ['definitions' => require dirname(__DIR__) . '/settings.php'],
    ],

    IdentityRepositoryInterface::class => IdentityRepository::class,
    IdentityWithTokenRepositoryInterface::class => IdentityRepository::class,

    PasswordPolicy::class => [
        '__construct()' => [
            'minLength' => $security['password']['minLength'],
            'requireMixedCase' => $security['password']['requireMixedCase'],
            'requireDigit' => $security['password']['requireDigit'],
            'requireSymbol' => $security['password']['requireSymbol'],
        ],
    ],

    LoginThrottle::class => [
        '__construct()' => [
            'maxAttemptsPerUser' => $security['login']['maxAttemptsPerUser'],
            'maxAttemptsPerIp' => $security['login']['maxAttemptsPerIp'],
            'windowSeconds' => $security['login']['windowSeconds'],
            'lockoutSeconds' => $security['login']['lockoutSeconds'],
        ],
    ],

    MfaService::class => [
        '__construct()' => ['issuer' => $security['mfa']['issuer']],
    ],

    AuthenticationService::class => [
        '__construct()' => ['mfaEnforcedRoles' => $security['mfa']['enforceForRoles']],
    ],

    SessionRegistry::class => [
        '__construct()' => [
            'idleTimeout' => $security['session']['idleTimeout'],
            'absoluteTimeout' => $security['session']['absoluteTimeout'],
        ],
    ],

    ApiTokenService::class => [
        '__construct()' => [
            'accessTtl' => $security['api']['tokenTtl'],
            'refreshTtl' => $security['api']['refreshTtl'],
        ],
    ],

    UserService::class => [
        '__construct()' => ['passwordHistory' => $security['password']['historySize']],
    ],
];
