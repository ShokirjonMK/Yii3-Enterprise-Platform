<?php

declare(strict_types=1);

namespace App\Admin\Monitoring;

use App\Platform\Monitoring\ObservabilityService;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;

/**
 * 17 · Monitoring & Observability: Prometheus (when reachable) or the in-app registry fallback,
 * Alertmanager alerts, Grafana/Jaeger links. The live region refreshes via HTMX every 30 s.
 */
final class MonitoringController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ObservabilityService $observability,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->responder->render('@admin/Monitoring/views/index', ['o' => $this->observability->overview()]);
    }

    public function live(): ResponseInterface
    {
        return $this->responder->fragment('@admin/Monitoring/views/_live', ['o' => $this->observability->overview()]);
    }
}
