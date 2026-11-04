<?php

declare(strict_types=1);

namespace App\Api\V1\Middleware;

use App\Api\V1\Http\ApiResponder;
use App\Platform\Settings\SettingsService;
use App\Shared\Infrastructure\Context\RequestContext;
use App\Shared\Infrastructure\RateLimit\RateLimitResult;
use App\Shared\Infrastructure\RateLimit\RedisRateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Fixed-window rate limiting (1 minute) shared by all instances through Redis:
 *  - requests with a Bearer token are counted per token (hash), others per client IP,
 *  - an additional per-IP ceiling (10x the limit) stops token rotation from bypassing limits.
 * Limit = setting `api.rate_limit_per_minute`. Every response carries X-RateLimit-Limit/Remaining/Reset;
 * exceeding the limit returns 429 with Retry-After. Fail-open when Redis is unavailable.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public const WINDOW = 60;

    public function __construct(
        private readonly RedisRateLimiter $limiter,
        private readonly SettingsService $settings,
        private readonly RequestContext $context,
        private readonly ApiResponder $responder,
        private readonly int $defaultLimit = 600,
    ) {}

    public static function tokenKey(string $token): string
    {
        return 'api:t:' . substr(hash('sha256', $token), 0, 40);
    }

    public static function ipKey(string $ip): string
    {
        return 'api:ip:' . $ip;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $limit = $this->limit();
        $ip = $this->context->ip() ?? 'unknown';
        $token = preg_match('/^Bearer\s+(\S+)$/', $request->getHeaderLine('Authorization'), $m) ? $m[1] : null;

        $result = $this->limiter->hit($token !== null ? self::tokenKey($token) : self::ipKey($ip), $limit, self::WINDOW);
        if ($token !== null && $result->allowed) {
            $ceiling = $this->limiter->hit('api:ipall:' . $ip, $limit * 10, self::WINDOW);
            if (!$ceiling->allowed) {
                $result = $ceiling;
            }
        }
        $headers = self::headers($result);
        if (!$result->allowed) {
            return $this->responder->error(429, 'too_many_requests', 'Rate limit exceeded. Retry after the indicated number of seconds.', [], $headers + [
                'Retry-After' => (string) max(1, $result->resetInSeconds),
            ]);
        }
        $response = $handler->handle($request);
        foreach ($headers as $k => $v) {
            $response = $response->withHeader($k, $v);
        }
        return $response;
    }

    /** @return array<string,string> */
    private static function headers(RateLimitResult $r): array
    {
        return [
            'X-RateLimit-Limit' => (string) $r->limit,
            'X-RateLimit-Remaining' => (string) $r->remaining,
            'X-RateLimit-Reset' => (string) $r->resetInSeconds,
        ];
    }

    private function limit(): int
    {
        try {
            $v = (int) $this->settings->get('api.rate_limit_per_minute');
        } catch (Throwable) {
            $v = 0;
        }
        return $v > 0 ? $v : $this->defaultLimit;
    }
}
