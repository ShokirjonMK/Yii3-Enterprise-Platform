<?php

declare(strict_types=1);

namespace App\Web\System;

use App\Platform\Monitoring\HealthChecker;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HealthController
{
    public function __construct(
        private readonly HealthChecker $health,
        private readonly Responder $responder,
        private readonly MetricsAuth $auth,
    ) {}

    public function live(): ResponseInterface
    {
        return $this->responder->json(['status' => 'up']);
    }

    public function ready(ServerRequestInterface $request): ResponseInterface
    {
        $results = $this->health->checkAll(['postgres', 'redis']);
        $overall = HealthChecker::overall($results);
        $body = ['status' => $overall];
        if ($this->auth->allowed($request)) {
            $body['checks'] = $this->health->checkAll();
        }
        return $this->responder->json($body, $overall === HealthChecker::DOWN ? 503 : 200);
    }
}
