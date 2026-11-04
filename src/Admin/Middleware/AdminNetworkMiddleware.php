<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Infrastructure\Context\RequestContext;
use App\Shared\Infrastructure\Http\ClientIp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Optional IP allowlist for the administration panel (setting security.allowed_admin_networks).
 */
final class AdminNetworkMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RequestContext $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $networks = (array) $this->settings->get('security.allowed_admin_networks');
        if ($networks !== []) {
            $ip = $this->context->ip();
            $allowed = false;
            foreach ($networks as $cidr) {
                if ($ip !== null && ClientIp::inCidr($ip, (string) $cidr)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new AccessDeniedException('The administration panel is not available from your network.');
            }
        }
        return $handler->handle($request);
    }
}
