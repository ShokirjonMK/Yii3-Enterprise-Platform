<?php

declare(strict_types=1);

use App\Environment;
use App\Shared\Infrastructure\Cache\FailSafeCache;
use App\Shared\Infrastructure\Context\RequestContext;
use App\Shared\Infrastructure\Database\ConnectionFactory;
use App\Shared\Infrastructure\Database\DatabaseRouter;
use App\Shared\Infrastructure\Lock\LockInterface;
use App\Shared\Infrastructure\Lock\RedisLock;
use App\Shared\Infrastructure\Logging\JsonStreamTarget;
use App\Shared\Infrastructure\RateLimit\RedisRateLimiter;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Security\Encrypter;
use App\Shared\Infrastructure\Session\RedisSessionHandler;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Yiisoft\Cache\Redis\RedisCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Definitions\Reference;
use Yiisoft\Log\Logger;
use Yiisoft\Security\PasswordHasher;

/** @var array $params */

$platform = $params['platform'];

return [
    RequestContext::class => [
        'class' => RequestContext::class,
        'reset' => function () {
            /** @var RequestContext $this */
            $this->reset();
        },
    ],

    LoggerInterface::class => static function (RequestContext $context, Psr\Container\ContainerInterface $container) use ($platform): LoggerInterface {
        $levels = Environment::isProd()
            ? [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO]
            : [];
        $logger = new Logger([
            new JsonStreamTarget('php://stderr', $context, $platform['observability']['serviceName'], $levels),
            // warning+ also into app_logs for Admin → Monitoring → Logs (flag logging.database)
            new App\Shared\Infrastructure\Logging\DatabaseLogTarget($container, $context),
        ]);
        $logger->setFlushInterval(100);
        return $logger;
    },

    // ----------------------------------------------------------------- PostgreSQL
    SchemaCache::class => [
        'class' => SchemaCache::class,
        '__construct()' => [Reference::to(Psr\SimpleCache\CacheInterface::class)],
        'setEnabled()' => [$params['yiisoft/db']['schema-cache']['enabled'] ?? true],
    ],

    ConnectionInterface::class => static fn(SchemaCache $schemaCache): ConnectionInterface
        => ConnectionFactory::create($platform['db'], $schemaCache, 'yii3-platform:' . PHP_SAPI),

    DatabaseRouter::class => static function (ConnectionInterface $primary, SchemaCache $schemaCache, LoggerInterface $logger) use ($platform): DatabaseRouter {
        $factories = [];
        foreach ($platform['db']['replicas'] as $replica) {
            $config = array_merge($platform['db'], $replica);
            $factories[] = static fn(): ConnectionInterface => ConnectionFactory::create($config, $schemaCache, 'yii3-platform:replica');
        }
        return new DatabaseRouter($primary, $factories, $platform['db']['replicaStickiness'], $logger);
    },

    // Named connections to other databases (PostgreSQL, MySQL/MariaDB, SQLite, SQL Server, Oracle, MongoDB, ClickHouse):
    // .env (DB_CONNECTIONS) + Admin → Databases. Use App\Platform\Database\Connections\DatabaseManager.
    App\Platform\Database\Connections\ConnectionStore::class => [
        'class' => App\Platform\Database\Connections\ConnectionStore::class,
        '__construct()' => [
            'platformDb' => $platform['db'],
            'envConnections' => $platform['databases'],
            'sqliteDirectory' => dirname(__DIR__, 3) . '/runtime/databases',
        ],
    ],

    // ----------------------------------------------------------------- Redis
    ClientInterface::class => static fn(): ClientInterface => RedisClientFactory::create($platform['redis']),

    Psr\SimpleCache\CacheInterface::class => static fn(LoggerInterface $logger): Psr\SimpleCache\CacheInterface
        => new FailSafeCache(
            // "cache" role (REDIS_CACHE_* = separate, evictable instance); entries without TTL expire after REDIS_CACHE_DEFAULT_TTL
            new RedisCache(RedisClientFactory::create(['prefix' => $platform['redisCache']['prefix'] . 'cache:'] + $platform['redisCache'])),
            $logger,
            defaultTtl: (int) $platform['redisCache']['defaultTtl'],
        ),

    RedisSessionHandler::class => [
        '__construct()' => [
            // Redis entry lives as long as the longest allowed session (admin setting security.session_absolute_hours)
            'ttl' => \Yiisoft\Definitions\DynamicReference::to(static fn(\App\Platform\Identity\Application\SecurityPolicy $p): int => $p->sessionAbsoluteSeconds()),
        ],
    ],

    LockInterface::class => RedisLock::class,
    RedisRateLimiter::class => RedisRateLimiter::class,

    // ----------------------------------------------------------------- Security primitives
    PasswordHasher::class => [
        '__construct()' => [
            PASSWORD_ARGON2ID,
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
        ],
    ],

    Encrypter::class => [
        '__construct()' => ['secret' => $platform['secret']],
    ],

    // ----------------------------------------------------------------- Mail
    TransportInterface::class => static fn(): TransportInterface => Transport::fromDsn($platform['mailer']['dsn']),
];
