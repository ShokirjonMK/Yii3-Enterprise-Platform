<?php

declare(strict_types=1);

namespace App\Platform\Monitoring\Tracing;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;

/**
 * OpenTelemetry tracer provider (OTLP/HTTP protobuf → otel-collector). Disabled (no-op, zero overhead)
 * unless OTEL_ENABLED=true. Spans are batched and flushed at shutdown, so request latency is not affected
 * by exporter round trips. Sampling: parent-based, ratio from OTEL_TRACES_SAMPLER_ARG (default 1.0).
 */
final class TracerProviderFactory
{
    private static ?TracerProviderInterface $provider = null;

    public static function create(array $observability, string $version, string $environment, float $ratio = 1.0): TracerProviderInterface
    {
        if (self::$provider !== null) {
            return self::$provider;
        }
        if (!($observability['otelEnabled'] ?? false)) {
            return self::$provider = new NoopTracerProvider();
        }
        try {
            $transport = (new OtlpHttpTransportFactory())->create(
                rtrim((string) $observability['otelEndpoint'], '/') . '/v1/traces',
                ContentTypes::PROTOBUF,
                [],
                null,
                2.0,
                100,
                1,
            );
            $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
                'service.name' => (string) $observability['serviceName'],
                'service.version' => $version,
                'deployment.environment' => $environment,
                'host.name' => (string) gethostname(),
            ])));
            $provider = new TracerProvider(
                new BatchSpanProcessor(new SpanExporter($transport), ClockFactory::getDefault()),
                new ParentBased($ratio >= 1.0 ? new AlwaysOnSampler() : new TraceIdRatioBasedSampler($ratio)),
                $resource,
            );
            register_shutdown_function(static fn() => $provider->shutdown());
            return self::$provider = $provider;
        } catch (Throwable) {
            return self::$provider = new NoopTracerProvider();
        }
    }
}
