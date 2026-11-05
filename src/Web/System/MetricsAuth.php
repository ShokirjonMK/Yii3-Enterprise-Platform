<?php

declare(strict_types=1);

namespace App\Web\System;

use App\Environment;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Access to /metrics and detailed health requires `Authorization: Bearer <METRICS_TOKEN>`
 * (Prometheus `authorization` scrape config). Without a configured token access is allowed only in dev.
 */
final class MetricsAuth
{
    public function __construct(private readonly ?string $token) {}

    public function allowed(ServerRequestInterface $request): bool
    {
        if ($this->token === null || $this->token === '') {
            return Environment::isDev();
        }
        return hash_equals('Bearer ' . $this->token, $request->getHeaderLine('Authorization'));
    }
}
