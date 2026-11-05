<?php

declare(strict_types=1);

namespace App\Api\V1\Http;

use App\Api\V1\Http\ApiResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlMatcherInterface;

/**
 * Catch-all route at the end of the /api/v1 group so that unmatched requests still pass through the
 * API middleware (CORS, rate limit) and get JSON errors:
 *   - path exists with other methods → 405 + Allow (OPTIONS → 204 + Allow)
 *   - otherwise → 404
 */
final class FallbackAction
{
    public const ROUTE = 'api.v1.fallback';
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly UrlMatcherInterface $matcher,
        private readonly ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $allowed = [];
        foreach (self::METHODS as $method) {
            if ($method === $request->getMethod()) {
                continue;
            }
            $result = $this->matcher->match($request->withMethod($method));
            if ($result->isSuccess() && $result->route()->getData('name') !== self::ROUTE) {
                $allowed[] = $method;
            }
        }
        if ($allowed !== []) {
            $allowed[] = 'OPTIONS';
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            $allow = implode(', ', $allowed);
            if ($request->getMethod() === 'OPTIONS') {
                return $this->responder->noContent()->withHeader('Allow', $allow);
            }
            return $this->responder->error(405, 'method_not_allowed', sprintf('Method %s is not allowed for this resource.', $request->getMethod()), [], ['Allow' => $allow]);
        }
        return $this->responder->error(404, 'not_found', 'Resource not found.');
    }
}
