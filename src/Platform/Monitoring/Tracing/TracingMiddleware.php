<?php

declare(strict_types=1);

namespace App\Platform\Monitoring\Tracing;

use App\Shared\Infrastructure\Context\RequestContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Yiisoft\Router\CurrentRoute;

/**
 * Server span per HTTP request with W3C trace-context propagation (incoming `traceparent`).
 * Attributes follow OTel HTTP semantic conventions; no headers, bodies or query strings are recorded.
 */
final class TracingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TracerProviderInterface $provider,
        private readonly CurrentRoute $route,
        private readonly RequestContext $context,
        private readonly ?\App\Platform\FeatureFlags\FeatureFlagService $flags = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->flags !== null && !$this->flags->isEnabled('observability.tracing')) {
            return $handler->handle($request);
        }
        $tracer = $this->provider->getTracer('yii3-platform.http');
        $parent = TraceContextPropagator::getInstance()->extract(
            array_map(static fn(array $v) => implode(',', $v), $request->getHeaders()),
        );
        $span = $tracer->spanBuilder('HTTP ' . $request->getMethod())
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($parent)
            ->setAttribute('http.request.method', $request->getMethod())
            ->setAttribute('url.path', $request->getUri()->getPath())
            ->setAttribute('app.correlation_id', $this->context->correlationId())
            ->startSpan();
        $scope = $span->storeInContext(Context::getCurrent())->activate();
        try {
            $response = $handler->handle($request);
            $route = $this->route->getName();
            if ($route !== null) {
                $span->updateName('HTTP ' . $request->getMethod() . ' ' . $route);
                $span->setAttribute('http.route', $route);
            }
            $span->setAttribute('http.response.status_code', $response->getStatusCode());
            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }
            if ($this->context->userId() !== null) {
                $span->setAttribute('enduser.id', $this->context->userId());
            }
            return $response;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e::class);
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
