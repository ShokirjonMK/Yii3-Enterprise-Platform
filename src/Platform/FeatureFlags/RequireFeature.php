<?php

declare(strict_types=1);

namespace App\Platform\FeatureFlags;

use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Context\RequestContext;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route-group gate for optional features: a disabled feature behaves as if it did not exist (404),
 * so switched-off functionality exposes no attack surface. Evaluated per tenant/user (rollouts, overrides).
 * Exception: in the admin panel a GET of a disabled section shows an explanatory page (with an "Enable"
 * button for users allowed to manage feature flags) instead of a bare 404 — admins are already
 * authenticated and authorized for the panel, so nothing is disclosed.
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
        private readonly ContainerInterface $container,
        private readonly bool $explain = false,
    ) {}

    public static function for(string $flag, bool $explain = false): array
    {
        return ['class' => self::class, '__construct()' => ['flag' => $flag, 'explain' => $explain]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->flags->isEnabled($this->flag, $this->context->tenantId(), $this->context->userId())) {
            if ($this->explain && $request->getMethod() === 'GET') {
                return $this->container->get(Responder::class)->render('components/feature-disabled', [
                    'flag' => $this->flags->get($this->flag),
                    'returnPath' => $request->getUri()->getPath(),
                ]);
            }
            throw new NotFoundException('This feature is disabled.');
        }
        return $handler->handle($request);
    }
}
