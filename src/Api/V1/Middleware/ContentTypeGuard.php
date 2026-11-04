<?php

declare(strict_types=1);

namespace App\Api\V1\Middleware;

use App\Api\V1\Http\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Request bodies must be JSON (415 otherwise). The OAuth-style auth endpoints additionally accept
 * application/x-www-form-urlencoded (RFC 6749 compatibility).
 */
final class ContentTypeGuard implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true) && $this->hasBody($request)) {
            $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
            $isAuth = (bool) preg_match('#/api/v1/auth/(token|refresh|revoke)$#', $request->getUri()->getPath());
            $ok = $type === 'application/json' || str_ends_with($type, '+json')
                || ($isAuth && $type === 'application/x-www-form-urlencoded');
            if (!$ok) {
                throw new ApiException(415, 'unsupported_media_type', 'Request bodies must be sent as application/json.');
            }
        }
        return $handler->handle($request);
    }

    private function hasBody(ServerRequestInterface $request): bool
    {
        $size = $request->getBody()->getSize();
        if ($size !== null) {
            return $size > 0;
        }
        return (string) $request->getBody() !== '';
    }
}
