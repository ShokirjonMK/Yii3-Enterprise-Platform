<?php

declare(strict_types=1);

namespace App\Web\Middleware;

use App\Web\Auth\WebAuthSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Step-up authentication for critical operations (role grants, SSO config, backups, deploys, purges):
 * requires password + MFA confirmation within the last N seconds.
 */
final class RequireRecentAuth implements MiddlewareInterface
{
    public function __construct(
        private readonly WebAuthSession $auth,
        private readonly UrlGeneratorInterface $urls,
        private readonly ResponseFactoryInterface $responses,
        private readonly int $maxAgeSeconds = 300,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'GET' || $request->getMethod() === 'HEAD') {
            return $handler->handle($request);
        }
        $age = $this->auth->reauthAge();
        if ($age !== null && $age <= $this->maxAgeSeconds) {
            return $handler->handle($request);
        }
        $return = (string) ($request->getHeaderLine('HX-Current-URL') ?: $request->getHeaderLine('Referer'));
        $path = parse_url($return, PHP_URL_PATH) ?: '/admin';
        $url = $this->urls->generate('auth.confirm', [], ['return' => $path]);
        return $this->responses->createResponse(303)->withHeader('Location', $url)->withHeader('HX-Redirect', $url);
    }
}
