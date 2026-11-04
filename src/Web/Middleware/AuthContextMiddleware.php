<?php

declare(strict_types=1);

namespace App\Web\Middleware;

use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Domain\Identity;
use App\Shared\Infrastructure\Context\RequestContext;
use App\Web\Auth\WebAuthSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/**
 * For every session-authenticated request:
 *  - validates the server-side session registry (revocation, idle & absolute timeout),
 *  - publishes actor/tenant into RequestContext (logs, audit),
 *  - enforces login gates (forced password change, mandatory MFA enrolment).
 */
final class AuthContextMiddleware implements MiddlewareInterface
{
    /** Routes reachable while a gate is pending. */
    private const GATE_ROUTES = [
        'password' => ['auth.password.change', 'auth.password.submit', 'auth.logout', 'auth.locale'],
        'mfa_setup' => ['auth.mfa.setup', 'auth.mfa.setup.confirm', 'auth.logout', 'auth.locale'],
    ];

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly SessionInterface $session,
        private readonly SessionRegistry $registry,
        private readonly RequestContext $context,
        private readonly WebAuthSession $auth,
        private readonly CurrentRoute $route,
        private readonly UrlGeneratorInterface $urls,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->currentUser->isGuest()) {
            $identity = $this->currentUser->getIdentity();
            $sessionId = $this->session->getId();
            $check = $sessionId === null || !$identity instanceof Identity
                ? ['valid' => false, 'reason' => 'no_session', 'row' => null]
                : $this->registry->validate($sessionId, (string) $identity->getId());

            if (!$check['valid']) {
                $this->auth->logout('session_' . $check['reason']);
                return $this->redirect('auth.login', ['reason' => (string) $check['reason']]);
            }
            $this->registry->touch((string) $sessionId, $check['row']);
            $this->context->setUserId($identity->user->id);
            $this->context->setTenantId($identity->user->tenantId);
            $request = $request->withAttribute('identity', $identity);

            // Gates are completed in a fixed order (password change first, then MFA enrolment); only the
            // first pending gate is enforced, otherwise two gates would redirect to each other.
            foreach (array_keys(self::GATE_ROUTES) as $gate) {
                if (!in_array($gate, $this->auth->gates(), true)) {
                    continue;
                }
                $allowed = self::GATE_ROUTES[$gate];
                if (!in_array($this->route->getName(), $allowed, true)) {
                    return $this->redirect($allowed[0]);
                }
                break;
            }
        }
        return $handler->handle($request);
    }

    private function redirect(string $route, array $query = []): ResponseInterface
    {
        $url = $this->urls->generate($route, [], $query);
        return $this->responses->createResponse(303)->withHeader('Location', $url)->withHeader('HX-Redirect', $url);
    }
}
