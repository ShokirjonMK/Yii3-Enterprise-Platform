<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * OWASP secure headers. CSP is strict (no inline scripts/styles); views must use external assets.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @param array<string,string> $csp */
    public function __construct(
        private readonly array $csp,
        private readonly bool $hsts = false,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withoutHeader('X-Powered-By')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');

        if (!$response->hasHeader('Content-Security-Policy')) {
            $policy = [];
            foreach ($this->csp as $directive => $value) {
                $policy[] = $directive . ' ' . $value;
            }
            $response = $response->withHeader('Content-Security-Policy', implode('; ', $policy));
        }
        if ($this->hsts) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $contentType = $response->getHeaderLine('Content-Type');
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, '/assets/') && (str_contains($contentType, 'text/html') || str_contains($contentType, 'application/json'))
            && !$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }
        return $response;
    }
}
