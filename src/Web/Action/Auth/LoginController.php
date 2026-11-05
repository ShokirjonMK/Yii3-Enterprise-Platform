<?php

declare(strict_types=1);

namespace App\Web\Action\Auth;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Identity\Application\AuthenticationService;
use App\Platform\Identity\Domain\AuthPolicy;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Mfa\MfaManager;
use App\Platform\Identity\Mfa\TrustedDevices;
use App\Platform\Identity\Sso\SsoProviderRepository;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\TooManyRequestsException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Context\RequestContext;
use App\Shared\Infrastructure\Http\Responder;
use App\Web\Auth\SecondFactor;
use App\Web\Auth\WebAuthSession;
use App\Web\Middleware\RequireLogin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/**
 * Login (first factor), second factor (any enrolled method: authenticator app, passkey, E-IMZO, Telegram /
 * e-mail / SMS / voice code, recovery code), "remember this device", logout, locale switch.
 */
final class LoginController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AuthenticationService $auth,
        private readonly WebAuthSession $webAuth,
        private readonly CurrentUser $currentUser,
        private readonly SessionInterface $session,
        private readonly FeatureFlagService $flags,
        private readonly SsoProviderRepository $sso,
        private readonly SecondFactor $secondFactor,
        private readonly MfaManager $mfa,
        private readonly TrustedDevices $devices,
        private readonly RequestContext $context,
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

        $trusted = $result->mfaRequired && $this->devices->isTrusted($result->user->id,
            $request->getCookieParams()[TrustedDevices::COOKIE] ?? null, $this->mfa->policy($result->user)->rememberDays);
        return $this->continue($this->webAuth->begin($result, $trusted));
    }

    public function mfaShow(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->webAuth->pendingUser();
        if ($user === null) {
            return $this->responder->redirect('auth.login');
        }
        $method = $request->getQueryParams()['method'] ?? null;
        return $this->mfaPage($user, is_string($method) ? $method : null);
    }

    /** Sends a one-time code (Telegram / e-mail / SMS / voice) for the pending sign-in. */
    public function mfaSend(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->webAuth->pendingUser();
        if ($user === null) {
            return $this->responder->redirect('auth.login');
        }
        $method = (string) (((array) $request->getParsedBody())['method'] ?? '');
        try {
            $this->secondFactor->send($user, $method, 'login');
            $this->responder->success('The code has been sent.');
        } catch (TooManyRequestsException|RuntimeException|NotFoundException|ValidationException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('auth.mfa', [], ['method' => $method]);
    }

    public function mfaPasskeyOptions(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->webAuth->pendingUser();
        if ($user === null) {
            return $this->responder->json(['error' => 'Your sign-in attempt expired.'], 401);
        }
        try {
            return $this->responder->json($this->secondFactor->passkeyOptions($user, $request, 'login'));
        } catch (NotFoundException $e) {
            return $this->responder->json(['error' => $e->getMessage()], 404);
        }
    }

    public function mfaEimzoChallenge(): ResponseInterface
    {
        if ($this->webAuth->pendingUser() === null) {
            return $this->responder->json(['error' => 'Your sign-in attempt expired.'], 401);
        }
        try {
            return $this->responder->json($this->secondFactor->eimzoChallenge());
        } catch (RuntimeException $e) {
            return $this->responder->json(['error' => $e->getMessage()], 503);
        }
    }

    public function mfaSubmit(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->webAuth->pendingUser();
        if ($user === null) {
            $this->responder->error('Your sign-in attempt expired. Please sign in again.');
            return $this->responder->redirect('auth.login');
        }
        $body = (array) $request->getParsedBody();
        if (!$this->secondFactor->verify($user, $request, 'login')) {
            $left = $this->webAuth->registerMfaFailure();
            $this->auth->recordMfaFailure($user);
            if ($left === 0) {
                $this->responder->error('Too many invalid codes. Please sign in again.');
                return $this->responder->redirect('auth.login');
            }
            $method = (string) ($body['method'] ?? '');
            return $this->mfaPage($user, $method !== '' ? $method : null, ['error' => 'Invalid verification code.', 'left' => $left], 422);
        }
        $remember = !empty($body['remember']) ? $this->mfa->policy($user)->rememberDays : 0;
        $response = $this->continue($this->webAuth->completePending());
        if ($remember > 0) {
            $token = $this->devices->issue($user->id, $remember, (string) $this->context->userAgent(), $this->context->ip());
            $response = $response->withAddedHeader('Set-Cookie', sprintf('%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s',
                TrustedDevices::COOKIE, $token, $remember * 86400, $request->getUri()->getScheme() === 'https' ? '; Secure' : ''));
        }
        return $response;
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

    private function mfaPage(User $user, ?string $method, array $extra = [], int $status = 200): ResponseInterface
    {
        $params = $this->secondFactor->params($user, $method, 'login') + [
            'rememberDays' => $this->mfa->policy($user)->rememberDays,
            'routes' => ['submit' => 'auth.mfa.submit', 'send' => 'auth.mfa.send', 'self' => 'auth.mfa', 'passkey' => 'auth.mfa.passkey', 'eimzo' => 'auth.mfa.eimzo', 'cancel' => 'auth.login'],
        ];
        $response = $this->responder->render('auth/mfa', $extra + $params, 'auth', $status);
        return $params['selected'] === 'eimzo' ? $response->withHeader('X-CSP-Connect', SecondFactor::EIMZO_CONNECT) : $response;
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
