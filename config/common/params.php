<?php

declare(strict_types=1);

use App\Environment as Env;
use App\Shared\ApplicationParams;
use App\Shared\Infrastructure\View\ViewHelpers;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Assets\AssetManager;
use Yiisoft\Definitions\Reference;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\Yii\View\Renderer\CsrfViewInjection;

$root = dirname(__DIR__, 2);

$replicas = array_map(
    static function (string $hostPort): array {
        [$host, $port] = array_pad(explode(':', $hostPort, 2), 2, '5432');
        return ['host' => $host, 'port' => $port];
    },
    Env::list('DB_REPLICAS'),
);

return [
    'application' => require __DIR__ . '/application.php',

    'platform' => [
        'name' => Env::string('APP_NAME', 'Yii3 Enterprise Platform'),
        'url' => rtrim(Env::string('APP_URL', 'http://localhost:8080'), '/'),
        'secret' => Env::string('APP_SECRET'),
        'version' => '1.0.0',
        'author' => 'ShokirjonMK',
        'authorUrl' => 'https://github.com/ShokirjonMK',
        'repository' => 'https://github.com/ShokirjonMK/Yii3-Enterprise-Platform',
        'locales' => ['uz' => "O'zbekcha", 'ru' => 'Русский', 'en' => 'English'],
        'defaultLocale' => Env::string('APP_LOCALE', 'uz'),
        'trustedProxies' => Env::list('TRUSTED_PROXIES'),

        'db' => [
            'host' => Env::string('DB_HOST', 'postgres'),
            'port' => Env::string('DB_PORT', '5432'),
            'name' => Env::isTest() ? Env::string('DB_TEST_NAME', 'platform_test') : Env::string('DB_NAME', 'platform'),
            'user' => Env::string('DB_USER', 'platform'),
            'password' => Env::string('DB_PASSWORD'),
            'sslmode' => Env::string('DB_SSLMODE', 'disable'),
            'replicas' => $replicas,
            // Reads stick to the primary for this many seconds after a write in the same session
            'replicaStickiness' => Env::int('DB_REPLICA_STICKINESS', 5),
            'statementTimeoutMs' => Env::int('DB_STATEMENT_TIMEOUT_MS', 30000),
        ],

        'redis' => [
            'mode' => Env::string('REDIS_MODE', 'standalone'),
            'host' => Env::string('REDIS_HOST', 'redis'),
            'port' => Env::int('REDIS_PORT', 6379),
            'password' => Env::nullableString('REDIS_PASSWORD'),
            'database' => Env::isTest() ? Env::int('REDIS_TEST_DB', 15) : Env::int('REDIS_DB', 0),
            'sentinels' => Env::list('REDIS_SENTINELS'),
            'sentinelService' => Env::string('REDIS_SENTINEL_SERVICE', 'mymaster'),
            'prefix' => Env::string('REDIS_PREFIX', 'yep:') . (Env::isTest() ? 'test:' : ''),
            'timeout' => 1.5,
        ],

        'kafka' => [
            'enabled' => Env::bool('KAFKA_ENABLED', true),
            'brokers' => Env::string('KAFKA_BROKERS', 'kafka:9092'),
            'clientId' => Env::string('KAFKA_CLIENT_ID', 'yii3-platform'),
            'topicPrefix' => Env::string('KAFKA_TOPIC_PREFIX', ''),
            'securityProtocol' => Env::string('KAFKA_SECURITY_PROTOCOL', 'PLAINTEXT'),
            'saslMechanism' => Env::nullableString('KAFKA_SASL_MECHANISM'),
            'saslUsername' => Env::nullableString('KAFKA_SASL_USERNAME'),
            'saslPassword' => Env::nullableString('KAFKA_SASL_PASSWORD'),
            'sslCaLocation' => Env::nullableString('KAFKA_SSL_CA_LOCATION'),
        ],

        's3' => [
            'endpoint' => Env::nullableString('S3_ENDPOINT'),
            'region' => Env::string('S3_REGION', 'us-east-1'),
            'bucket' => Env::string('S3_BUCKET', 'platform'),
            'key' => Env::string('S3_KEY'),
            'secret' => Env::string('S3_SECRET'),
            'pathStyle' => Env::bool('S3_PATH_STYLE', true),
        ],

        'mailer' => [
            'dsn' => Env::string('MAILER_DSN', 'null://null'),
            'from' => Env::string('MAIL_FROM', 'noreply@platform.local'),
        ],

        'observability' => [
            'otelEnabled' => Env::bool('OTEL_ENABLED', false),
            'otelEndpoint' => Env::string('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://otel-collector:4318'),
            'serviceName' => Env::string('OTEL_SERVICE_NAME', 'yii3-platform'),
            'prometheusUrl' => Env::nullableString('PROMETHEUS_URL'),
            'metricsToken' => Env::nullableString('METRICS_TOKEN'),
            'grafanaUrl' => Env::nullableString('GRAFANA_URL'),
        ],

        'alerts' => [
            'telegramBotToken' => Env::nullableString('TELEGRAM_BOT_TOKEN'),
            'telegramChatId' => Env::nullableString('TELEGRAM_ALERT_CHAT_ID'),
        ],

        'security' => [
            'password' => [
                'minLength' => 12,
                'requireMixedCase' => true,
                'requireDigit' => true,
                'requireSymbol' => true,
                'historySize' => 5,
                'maxAgeDays' => 180,
            ],
            'login' => [
                'maxAttemptsPerUser' => 5,
                'maxAttemptsPerIp' => 30,
                'windowSeconds' => 900,
                'lockoutSeconds' => 900,
            ],
            'session' => [
                'idleTimeout' => 1800,
                'absoluteTimeout' => 43200,
                'reauthWindow' => 300,
            ],
            'mfa' => [
                'issuer' => Env::string('APP_NAME', 'Yii3 Enterprise Platform'),
                'enforceForRoles' => ['superadmin', 'platform_admin', 'security_admin', 'devops_sre'],
            ],
            'api' => [
                'tokenTtl' => 3600,
                'refreshTtl' => 2592000,
                'rateLimitPerMinute' => 600,
            ],
            'csp' => [
                'default-src' => "'self'",
                'script-src' => "'self'",
                'style-src' => "'self'",
                'img-src' => "'self' data:",
                'font-src' => "'self'",
                'connect-src' => "'self'",
                'frame-ancestors' => "'none'",
                'form-action' => "'self'",
                'base-uri' => "'self'",
                'object-src' => "'none'",
            ],
        ],
    ],

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    'yiisoft/view' => [
        'basePath' => '@views',
        'parameters' => [
            'assetManager' => Reference::to(AssetManager::class),
            'applicationParams' => Reference::to(ApplicationParams::class),
            'aliases' => Reference::to(Aliases::class),
            'urlGenerator' => Reference::to(UrlGeneratorInterface::class),
            'currentRoute' => Reference::to(CurrentRoute::class),
            'translator' => Reference::to(TranslatorInterface::class),
            'h' => Reference::to(ViewHelpers::class),
        ],
    ],

    'yiisoft/yii-view-renderer' => [
        'viewPath' => '@views',
        'layout' => '@views/layouts/web.php',
        'injections' => [
            Reference::to(CsrfViewInjection::class),
            Reference::to(App\Shared\Infrastructure\View\PlatformViewInjection::class),
        ],
    ],

    'yiisoft/translator' => [
        'locale' => Env::string('APP_LOCALE', 'uz'),
        'fallbackLocale' => 'en',
        'defaultCategory' => 'app',
    ],

    'yiisoft/session' => [
        'session' => [
            'options' => [
                'name' => Env::isProd() ? '__Host-yep_sid' : 'yep_sid',
                'cookie_secure' => Env::isProd() ? 1 : 0,
                'cookie_httponly' => 1,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => 1,
                'gc_maxlifetime' => 43200,
            ],
            'handler' => Reference::to(App\Shared\Infrastructure\Session\RedisSessionHandler::class),
        ],
    ],

    'yiisoft/user' => [
        'authUrl' => '/login',
        'cookieLogin' => [
            'forceAddCookie' => false,
            'duration' => null,
        ],
    ],

    'yiisoft/db-migration' => [
        'newMigrationNamespace' => 'App\\Migration',
        'newMigrationPath' => $root . '/migrations',
        'sourceNamespaces' => [],
        'sourcePaths' => [
            $root . '/vendor/yiisoft/rbac-db/migrations/items',
            $root . '/vendor/yiisoft/rbac-db/migrations/assignments',
            $root . '/migrations',
        ],
        'databases' => [],
    ],

    'yiisoft/db' => [
        'schema-cache' => ['enabled' => !Env::isDev()],
    ],

    // The vendor Gii JSON API (/gii/api, CORS *) is never exposed directly; Enterprise Gii is served
    // through the authenticated admin panel (Developer Tools) and console, non-production only.
    'yiisoft/yii-gii' => [
        'enabled' => false,
    ],
];
