<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Identity\Application\AuthenticationService;
use App\Platform\Identity\Sso\SsoException;
use App\Platform\Identity\Sso\SsoService;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;

final class SsoController
{
    private const STATE_KEY = 'sso.state';

    public function __construct(
        private readonly SsoService $sso,
        private readonly SessionInterface $session,
        private readonly Responder $responder,
        private readonly WebAuthSession $webAuth,
        private readonly AuthenticationService $auth,
        private readonly FeatureFlagService $flags,
    ) {}

    public function start(CurrentRoute $route): ResponseInterface
    {
        if (!$this->flags->isEnabled('auth.sso')) {
            throw new NotFoundException('Single sign-on is disabled.');
        }
        $code = (string) $route->getArgument('provider');
        $req = $this->sso->start($code);
        $this->session->set(self::STATE_KEY, $req['state']);
        return $this->responder->redirectToUrl($req['url'], 302)->withHeader('HX-Redirect', $req['url']);
    }

    public function callback(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $code = (string) $route->getArgument('provider');
        $state = $this->session->get(self::STATE_KEY);
        $this->session->remove(self::STATE_KEY); // single use
        try {
            if (!is_array($state)) {
                throw new SsoException('Invalid or expired sign-in state. Please try again.', 'invalid_state');
            }
            $user = $this->sso->complete($code, $request->getQueryParams(), $state);
        } catch (SsoException|NotFoundException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('auth.login');
        }
        $result = $this->auth->result($user, 'sso:' . $code);
        $next = $this->webAuth->begin($result);
        return $this->responder->redirect($next);
    }
}
