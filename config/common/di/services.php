<?php

declare(strict_types=1);

use App\Platform\Messaging\Kafka\KafkaConfig;
use App\Platform\Monitoring\HealthChecker;
use App\Platform\Monitoring\Metrics;
use App\Platform\Storage\ObjectStorage;
use App\Platform\Identity\Sso\SsoService;

/** @var array $params */

$platform = $params['platform'];

return [
    GuzzleHttp\ClientInterface::class => static fn(): GuzzleHttp\ClientInterface => new GuzzleHttp\Client([
        'timeout' => 15,
        'connect_timeout' => 5,
        'http_errors' => true,
        'allow_redirects' => ['max' => 3, 'protocols' => ['https', 'http']],
        'headers' => ['User-Agent' => 'Yii3EnterprisePlatform/1.0'],
    ]),
    SsoService::class => [
        '__construct()' => ['baseUrl' => $platform['url']],
    ],
    App\Platform\Queue\HandlerRegistry::class => static fn(Psr\Container\ContainerInterface $c): App\Platform\Queue\HandlerRegistry
        => new App\Platform\Queue\HandlerRegistry($c, require dirname(__DIR__) . '/jobs.php'),
    App\Platform\Messaging\Kafka\KafkaConsumerRunner::class => [
        '__construct()' => ['groups' => require dirname(__DIR__) . '/events.php'],
    ],
    App\Platform\Notifications\ChannelRegistry::class => static fn(Psr\Container\ContainerInterface $c): App\Platform\Notifications\ChannelRegistry
        => new App\Platform\Notifications\ChannelRegistry($c, require dirname(__DIR__) . '/notification-channels.php'),
    App\Platform\Notifications\Channel\EmailChannel::class => [
        '__construct()' => ['from' => $platform['mailer']['from'], 'dsn' => $platform['mailer']['dsn']],
    ],
    OpenTelemetry\API\Trace\TracerProviderInterface::class => static fn(): OpenTelemetry\API\Trace\TracerProviderInterface
        => App\Platform\Monitoring\Tracing\TracerProviderFactory::create(
            $platform['observability'],
            $platform['version'],
            App\Environment::appEnv(),
            (float) App\Environment::string('OTEL_TRACES_SAMPLER_ARG', '1.0'),
        ),
    KafkaConfig::class => static fn(): KafkaConfig => KafkaConfig::fromParams($platform['kafka']),
    ObjectStorage::class => [
        '__construct()' => ['config' => $platform['s3']],
    ],
    Metrics::class => [
        '__construct()' => ['redisConfig' => $platform['redis']],
    ],
    App\Web\Action\System\MetricsAuth::class => [
        '__construct()' => ['token' => $platform['observability']['metricsToken']],
    ],
    HealthChecker::class => [
        '__construct()' => ['mailer' => $platform['mailer']],
    ],
];
