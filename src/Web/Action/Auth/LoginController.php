<?php

declare(strict_types=1);

namespace App\Web\Action\Auth;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Identity\Application\AuthenticationService;
use App\Platform\Identity\Application\MfaService;
use App\Platform\Identity\Domain\AuthPolicy;
use App\Platform\Identity\Sso\SsoProviderRepository;
use App\Shared\Domain\Exception\TooManyRequestsException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use App\Web\Auth\WebAuthSession;
use App\Web\Middleware\RequireLogin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/**
 * Login (first factor), MFA verification (second factor), logout, locale switch.
 */
final class LoginController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AuthenticationService $auth,
        private readonly WebAuthSession $webAuth,
        private readonly CurrentUser $currentUser,
        private readonly MfaService $mfa,
        private readonly SessionInterface $session,
        private readonly FeatureFlagService $flags,
        private readonly SsoProviderRepository $sso,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUser->isGuest()) {
            return $this->responder->redirect('home.redirect');
        }
        $return = RequireLogin::safeReturn($request->getQueryParams()['return'] ?? null);
        if ($return !== null) {
            $this->session->set('auth.return', $return);
        }
        return $this->page(['reason' => $request->getQueryParams()['reason'] ?? null]);
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $login = trim((string) ($body['login'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if (!$this->flags->isEnabled('auth.local_login')) {
            // Break-glass: accounts with policy local_only may always use a password
            $candidate = $login !== '' ? $this->auth->findForBreakGlass($login) : null;
            if ($candidate === null || $candidate->authPolicy !== AuthPolicy::LOCAL_ONLY) {
                return $this->page(['error' => 'Password sign-in is disabled. Please use single sign-on.', 'login' => $login], 403);
            }
        }

        try {
            $result = $this->auth->authenticate($login, $password);
        } catch (ValidationException $e) {
            return $this->page(['error' => $e->getMessage(), 'login' => $login], 422);
        } catch (TooManyRequestsException $e) {
            return $this->page(['error' => $e->getMessage(), 'login' => $login], 429)->withHeader('Retry-After', (string) $e->retryAfter());
        }

        $next = $this->webAuth->begin($result);
        return $this->continue($next);
    }

    public function mfaShow(): ResponseInterface
    {
        if ($this->webAuth->pendingUser() === null) {
            return $this->responder->redirect('auth.login');
        }
        return $this->responder->render('auth/mfa', [], 'auth');
    }

    public function mfaSubmit(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->webAuth->pendingUser();
        if ($user === null) {
            $this->responder->error('Your sign-in attempt expired. Please sign in again.');
            return $this->responder->redirect('auth.login');
        }
        $code = (string) (((array) $request->getParsedBody())['code'] ?? '');
        if (!$this->mfa->verifyForUser($user, $code)) {
            $left = $this->webAuth->registerMfaFailure();
            $this->auth->recordMfaFailure($user);
            if ($left === 0) {
                $this->responder->error('Too many invalid codes. Please sign in again.');
                return $this->responder->redirect('auth.login');
            }
            return $this->responder->render('auth/mfa', ['error' => 'Invalid verification code.', 'left' => $left], 'auth', 422);
        }
        return $this->continue($this->webAuth->completePending());
    }

    public function logout(): ResponseInterface
    {
        $this->webAuth->logout();
        return $this->responder->redirect('auth.login');
    }

    public function locale(ServerRequestInterface $request): ResponseInterface
    {
        $locale = (string) (((array) $request->getParsedBody())['locale'] ?? '');
        if (in_array($locale, ['uz', 'ru', 'en'], true)) {
            $this->session->set('locale', $locale);
        }
        $back = RequireLogin::safeReturn(parse_url($request->getHeaderLine('Referer'), PHP_URL_PATH) ?: null) ?? '/';
        return $this->responder->redirectToUrl($back);
    }

    private function continue(string $route): ResponseInterface
    {
        if ($route === 'home.redirect') {
            $return = RequireLogin::safeReturn($this->session->get('auth.return'));
            $this->session->remove('auth.return');
            if ($return !== null) {
                return $this->responder->redirectToUrl($return);
            }
        }
        return $this->responder->redirect($route);
    }

    private function page(array $params = [], int $status = 200): ResponseInterface
    {
        return $this->responder->render('auth/login', $params + [
            'localEnabled' => $this->flags->isEnabled('auth.local_login'),
            'providers' => $this->flags->isEnabled('auth.sso') ? $this->sso->enabledForLogin() : [],
        ], 'auth', $status);
    }
}
