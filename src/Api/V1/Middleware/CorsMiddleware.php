<?php

declare(strict_types=1);

namespace App\Api\V1\Middleware;

use App\Platform\Settings\SettingsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * CORS for the stateless API. Origins come from the setting `api.cors_origins` (exact match, no
 * wildcards). No credentials mode: the API uses Bearer tokens, never cookies.
 * Preflight requests (OPTIONS + Access-Control-Request-Method) are answered here with 204.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public const ALLOW_METHODS = 'GET, POST, PATCH, PUT, DELETE, OPTIONS';
    public const ALLOW_HEADERS = 'Authorization, Content-Type, Accept, Accept-Language, If-Match, X-Request-Id';
    public const EXPOSE_HEADERS = 'ETag, Location, Retry-After, X-Request-Id, X-Total-Count, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset';
    public const MAX_AGE = 600;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && in_array($origin, $this->origins(), true);

        if ($request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            $response = $this->responses->createResponse(204)->withHeader('Vary', 'Origin');
            if (!$allowed) {
                return $response; // no CORS headers: the browser blocks the cross-origin request
            }
            return $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Methods', self::ALLOW_METHODS)
                ->withHeader('Access-Control-Allow-Headers', self::ALLOW_HEADERS)
                ->withHeader('Access-Control-Max-Age', (string) self::MAX_AGE);
        }

        $response = $handler->handle($request);
        if ($origin === '') {
            return $response;
        }
        $response = $response->withAddedHeader('Vary', 'Origin');
        if ($allowed) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Expose-Headers', self::EXPOSE_HEADERS);
        }
        return $response;
    }

    /** @return list<string> */
    private function origins(): array
    {
        try {
            $v = $this->settings->get('api.cors_origins');
        } catch (Throwable) {
            return [];
        }
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }
}
