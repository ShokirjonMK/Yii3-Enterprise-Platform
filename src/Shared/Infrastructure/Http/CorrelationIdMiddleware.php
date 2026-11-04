<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Accepts X-Request-Id / traceparent from the edge (nginx), or generates one, stores it in the
 * RequestContext (logs, audit, outbox, outgoing HTTP calls) and echoes it in the response.
 */
final class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly ClientIp $clientIp,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine('X-Request-Id');
        if ($id === '' && preg_match('/^00-([0-9a-f]{32})-/', $request->getHeaderLine('traceparent'), $m)) {
            $id = $m[1];
        }
        $this->context->setCorrelationId($id !== '' ? $id : null);
        $this->context->setClient($this->clientIp->resolve($request), $request->getHeaderLine('User-Agent') ?: null);
        $this->context->setChannel(str_starts_with($request->getUri()->getPath(), '/api/') ? 'api' : 'web');

        $request = $request->withAttribute('correlationId', $this->context->correlationId());
        return $handler->handle($request)->withHeader('X-Request-Id', $this->context->correlationId());
    }
}
