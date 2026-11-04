<?php

declare(strict_types=1);

namespace App\Platform\FeatureFlags;

use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route-group gate for optional features: a disabled feature behaves as if it did not exist (404),
 * so switched-off functionality exposes no attack surface. Evaluated per tenant/user (rollouts, overrides).
 *
 *   Group::create()->middleware(RequireFeature::for('feature.integrations'))->routes(...)
 *
 * Author: ShokirjonMK
 */
final class RequireFeature implements MiddlewareInterface
{
    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly RequestContext $context,
        private readonly string $flag,
    ) {}

    public static function for(string $flag): array
    {
        return ['class' => self::class, '__construct()' => ['flag' => $flag]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->flags->isEnabled($this->flag, $this->context->tenantId(), $this->context->userId())) {
            throw new NotFoundException('This feature is disabled.');
        }
        return $handler->handle($request);
    }
}
