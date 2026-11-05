<?php

declare(strict_types=1);

namespace App\Web\System;

use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Aliases\Aliases;

/**
 * Fallback for unmatched routes. Runs outside any route group (no session/CSRF), so the 404 page is a
 * self-contained template and the API variant is the standard JSON error envelope.
 */
final class NotFoundHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly RequestContext $context,
        private readonly Aliases $aliases,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (str_starts_with($request->getUri()->getPath(), '/api/') || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $r = $this->responses->createResponse(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            $r->getBody()->write((string) json_encode(['error' => ['code' => 'not_found', 'message' => 'Resource not found.', 'correlation_id' => $this->context->correlationId()]]));
            return $r;
        }
        $correlationId = $this->context->correlationId();
        ob_start();
        require $this->aliases->get('@views/errors/not-found.php');
        $r = $this->responses->createResponse(404)->withHeader('Content-Type', 'text/html; charset=utf-8');
        $r->getBody()->write((string) ob_get_clean());
        return $r;
    }
}
