<?php

declare(strict_types=1);

namespace App\Web\System;

use App\Platform\Monitoring\MetricsCollector;
use App\Platform\Monitoring\Metrics;
use Prometheus\RenderTextFormat;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MetricsAction
{
    public function __construct(
        private readonly Metrics $metrics,
        private readonly MetricsCollector $collector,
        private readonly MetricsAuth $auth,
        private readonly ResponseFactoryInterface $responses,
        private readonly \App\Platform\FeatureFlags\FeatureFlagService $flags,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->flags->isEnabled('observability.metrics')) {
            return $this->responses->createResponse(404);
        }
        if (!$this->auth->allowed($request)) {
            return $this->responses->createResponse(401)->withHeader('WWW-Authenticate', 'Bearer');
        }
        $this->collector->collect();
        $renderer = new RenderTextFormat();
        $response = $this->responses->createResponse(200)->withHeader('Content-Type', RenderTextFormat::MIME_TYPE);
        $response->getBody()->write($renderer->render($this->metrics->registry()->getMetricFamilySamples()));
        return $response;
    }
}
