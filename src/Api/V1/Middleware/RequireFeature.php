<?php

declare(strict_types=1);

namespace App\Api\V1\Middleware;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Identity\Domain\Identity;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Feature-flag gate: when the flag is off (globally, for the tenant or outside the rollout) the routes
 * behind it do not exist (404). Works for the API (identity attribute from Bearer auth) and for
 * Admin/Web groups (identity attribute set by AuthContextMiddleware).
 *
 *   ->middleware(RequireFeature::for('module.projects'))
 */
final class RequireFeature implements MiddlewareInterface
{
    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly string $flag,
    ) {}

    public static function for(string $flag): array
    {
        return ['class' => self::class, '__construct()' => ['flag' => $flag]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $request->getAttribute('identity');
        $tenantId = $identity instanceof Identity ? $identity->user->tenantId : null;
        $userId = $identity instanceof Identity ? $identity->user->id : null;
        if (!$this->flags->isEnabled($this->flag, $tenantId, $userId)) {
            throw new NotFoundException('Resource not found.');
        }
        return $handler->handle($request);
    }
}
