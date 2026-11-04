<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * User activity log: who opened which page / API endpoint, when, from where, with what result and how long it
 * took. Recorded for authenticated web, admin and API requests while the flag `audit.user_activity` is on.
 *
 * Zero added latency: the row is written in a shutdown function after `fastcgi_finish_request()` has already
 * delivered the response. Query strings and bodies are never stored. Auto-refresh polling fragments are skipped.
 *
 * Author: ShokirjonMK
 */
final class ActivityLogMiddleware implements MiddlewareInterface
{
    /** Route-name suffixes of background polling (would flood the log without value). */
    private const SKIP_SUFFIXES = ['.kpis', '.health', '.live', '.badge', '.status'];

    /** @var list<array<string,mixed>> */
    private static array $pending = [];
    private static bool $registered = false;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FeatureFlagService $flags,
        private readonly RequestContext $context,
        private readonly CurrentRoute $route,
        private readonly CurrentUser $currentUser,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = hrtime(true);
        $response = $handler->handle($request);
        try {
            $this->capture($request, $response->getStatusCode(), (int) ((hrtime(true) - $start) / 1e6));
        } catch (Throwable) {
            // activity logging must never break a request
        }
        return $response;
    }

    private function capture(ServerRequestInterface $request, int $status, int $durationMs): void
    {
        $api = $this->context->channel() === 'api'; // stateless API: never touch the session-backed CurrentUser
        $userId = $this->context->userId() ?? ($api ? null : $this->currentUser->getId());
        if ($userId === null || !$this->flags->isEnabled('audit.user_activity')) {
            return;
        }
        $route = $this->route->getName();
        foreach (self::SKIP_SUFFIXES as $suffix) {
            if ($route !== null && str_ends_with($route, $suffix)) {
                return;
            }
        }
        $identity = $api ? null : $this->currentUser->getIdentity();
        self::$pending[] = [
            'user_id' => $userId,
            'username' => $identity instanceof \App\Platform\Identity\Domain\Identity ? $identity->user->username : null,
            'tenant_id' => $this->context->tenantId(),
            'channel' => $this->context->channel(),
            'method' => $request->getMethod(),
            'route' => $route === null ? null : mb_substr($route, 0, 128),
            'path' => mb_substr($request->getUri()->getPath(), 0, 512),
            'status' => $status,
            'duration_ms' => $durationMs,
            'ip' => $this->context->ip(),
            'user_agent' => $this->context->userAgent() === null ? null : mb_substr($this->context->userAgent(), 0, 255),
            'correlation_id' => $this->context->correlationId(),
        ];
        if (!self::$registered) {
            self::$registered = true;
            $db = $this->db;
            register_shutdown_function(static function () use ($db): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request(); // response is delivered before we touch the database
                }
                try {
                    if (self::$pending !== []) {
                        $db->createCommand()->insertBatch('user_activity', self::$pending)->execute();
                    }
                } catch (Throwable) {
                } finally {
                    self::$pending = [];
                    self::$registered = false;
                }
            });
        }
    }
}
