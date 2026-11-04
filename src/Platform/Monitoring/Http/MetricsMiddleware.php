<?php

declare(strict_types=1);

namespace App\Platform\Monitoring\Http;

use App\Platform\Monitoring\Metrics;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Yiisoft\Router\CurrentRoute;

/**
 * RED metrics per route: request count by status class and latency histogram (p95/p99 in Grafana).
 * Route *names* are used as labels (bounded cardinality), never raw paths.
 */
final class MetricsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Metrics $metrics,
        private readonly CurrentRoute $route,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = hrtime(true);
        $status = 500;
        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();
            return $response;
        } catch (Throwable $e) {
            $status = 500;
            throw $e;
        } finally {
            $path = $request->getUri()->getPath();
            if ($path !== '/metrics' && !str_starts_with($path, '/health')) {
                $route = $this->route->getName() ?? 'unmatched';
                $seconds = (hrtime(true) - $start) / 1e9;
                $labels = ['method' => $request->getMethod(), 'route' => $route, 'status' => (string) $status];
                $this->metrics->inc('http_requests_total', 'HTTP requests', $labels);
                $this->metrics->observe('http_request_duration_seconds', 'HTTP request latency', $seconds, ['method' => $request->getMethod(), 'route' => $route]);
            }
        }
    }
}
