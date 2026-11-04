<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Monitoring\Tracing\TracingMiddleware;
use App\Shared\Infrastructure\Context\RequestContext;
use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;

final class TracingMiddlewareTest extends TestCase
{
    public function testServerSpanContinuesIncomingTraceAndRecordsRoute(): void
    {
        $exporter = new InMemoryExporter();
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $mw = new TracingMiddleware($provider, new CurrentRoute(), new RequestContext());

        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $request = (new ServerRequest([], [], [], [], null, 'GET', '/admin/users'))
            ->withHeader('traceparent', "00-$traceId-00f067aa0ba902b7-01");
        $mw->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(503);
            }
        });

        $spans = $exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame($traceId, $spans[0]->getTraceId(), 'W3C trace context propagated');
        self::assertSame(503, $spans[0]->getAttributes()->get('http.response.status_code'));
        self::assertSame('Error', $spans[0]->getStatus()->getCode());
    }
}
