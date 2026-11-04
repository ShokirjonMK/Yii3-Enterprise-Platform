<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Webhook;

use App\Shared\Infrastructure\Http\ClientIp;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * POST /webhooks/{code} — public inbound endpoint (no session, no CSRF; authenticated by HMAC signature).
 * 202 accepted · 200 duplicate · 401 invalid signature · 404 unknown/disabled endpoint · 413 too large · 429 rate limited.
 */
final class WebhookReceiveAction
{
    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly ResponseFactoryInterface $responses,
        private readonly ClientIp $clientIp,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        $body = (string) $request->getBody();
        $result = $this->webhooks->receive((string) $route->getArgument('code'), $headers, $body, $this->ip($request));
        $response = $this->responses->createResponse($result['status'])->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write((string) json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }

    private function ip(ServerRequestInterface $request): ?string
    {
        return $this->clientIp->resolve($request);
    }
}
