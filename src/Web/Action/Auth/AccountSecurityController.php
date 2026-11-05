<?php

declare(strict_types=1);

namespace App\Web\Action\Auth;

use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Application\AuthenticationService;
use App\Platform\Identity\Application\MfaService;
use App\Platform\Identity\Application\UserService;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use App\Web\Auth\WebAuthSession;
use App\Web\Middleware\RequireLogin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/**
 * Forced password change gate and step-up re-authentication (password + any second factor).
 * Two-factor enrolment lives in MfaSetupController.
 */
final class AccountSecurityController
{
    private const REAUTH_PASSWORD = 'auth.reauth.password_at';

    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly UserService $users,
        private readonly UserRepository $repo,
        private readonly MfaService $mfa,
        private readonly WebAuthSession $webAuth,
        private readonly SessionInterface $session,
        private readonly AuthenticationService $auth,
        private readonly AuditLogger $audit,
        private readonly \App\Web\Auth\SecondFactor $secondFactor,
    ) {}

    public function passwordForm(): ResponseInterface
    {
        return $this->responder->render('auth/password-change', ['forced' => in_array('password', $this->webAuth->gates(), true)], 'auth');
    }

    public function passwordSubmit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $user = $this->user();
        if (($body['new_password'] ?? '') !== ($body['new_password_confirm'] ?? null)) {
            return $this->responder->render('auth/password-change', ['errors' => ['new_password_confirm' => ['Passwords do not match.']], 'forced' => true], 'auth', 422);
        }
        try {
            $this->users->changeOwnPassword($user, (string) ($body['current_password'] ?? ''), (string) ($body['new_password'] ?? ''), $this->webAuth->sessionId());
        } catch (ValidationException $e) {
            return $this->responder->render('auth/password-change', ['errors' => $e->errors(), 'forced' => in_array('password', $this->webAuth->gates(), true)], 'auth', 422);
        }
        $this->webAuth->clearGate('password');
        $this->responder->success('Your password has been changed.');
        return $this->responder->redirect($this->webAuth->nextRoute());
    }

    /**
     * Step-up re-authentication: 1) password (when the account has one), 2) any usable second factor.
     * A single POST with password + code (authenticator app or recovery code) is still accepted.
     */
    public function confirmForm(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $return = RequireLogin::safeReturn($request->getQueryParams()['return'] ?? null) ?? '/admin';
        $method = $request->getQueryParams()['method'] ?? null;
        return $this->confirmPage($user, $return, is_string($method) ? $method : null);
    }

    public function confirmSubmit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $user = $this->user();
        $return = RequireLogin::safeReturn($body['return'] ?? null) ?? '/admin';
        $passwordStep = array_key_exists('password', $body);
        if ($passwordStep) {
            if ($user->canUsePassword() && !$this->auth->verifyPassword($user, (string) $body['password'])) {
                return $this->confirmFailed($user, $return);
            }
            $this->session->set(self::REAUTH_PASSWORD, time());
        } elseif ($user->canUsePassword() && !$this->passwordConfirmed()) {
            return $this->confirmPage($user, $return, null);
        }
        if ($user->mfaEnabled) {
            if ($passwordStep && trim((string) ($body['code'] ?? '')) === '' && !isset($body['credential']) && !isset($body['pkcs7'])) {
                // password accepted: continue with the second factor
                return $this->responder->redirect('auth.confirm', [], ['return' => $return]);
            }
            if (!$this->secondFactor->verify($user, $request, 'reauth')) {
                return $this->confirmFailed($user, $return, (string) ($body['method'] ?? '') ?: null);
            }
        } elseif (!$user->canUsePassword()) {
            return $this->confirmFailed($user, $return); // SSO-only account without 2FA cannot step up
        }
        $this->session->remove(self::REAUTH_PASSWORD);
        $this->webAuth->markReauthenticated();
        $this->audit->security('auth.reauth.success', 'success', [], 'info', $user->id, $user->username);
        $this->responder->success('Identity confirmed. You can now repeat the action.');
        return $this->responder->redirectToUrl($return);
    }

    public function confirmSend(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $body = (array) $request->getParsedBody();
        $return = RequireLogin::safeReturn($body['return'] ?? null) ?? '/admin';
        $method = (string) ($body['method'] ?? '');
        try {
            $this->secondFactor->send($user, $method, 'reauth');
            $this->responder->success('The code has been sent.');
        } catch (\Throwable $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('auth.confirm', [], ['return' => $return, 'method' => $method]);
    }

    public function confirmPasskey(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->responder->json($this->secondFactor->passkeyOptions($this->user(), $request, 'reauth'));
        } catch (\App\Shared\Domain\Exception\NotFoundException $e) {
            return $this->responder->json(['error' => $e->getMessage()], 404);
        }
    }

    public function confirmEimzo(): ResponseInterface
    {
        try {
            return $this->responder->json($this->secondFactor->eimzoChallenge());
        } catch (\RuntimeException $e) {
            return $this->responder->json(['error' => $e->getMessage()], 503);
        }
    }

    private function confirmFailed(\App\Platform\Identity\Domain\User $user, string $return, ?string $method = null): ResponseInterface
    {
        $this->audit->security('auth.reauth.failure', 'failure', [], 'notice', $user->id, $user->username);
        return $this->confirmPage($user, $return, $method, 'Confirmation failed.', 422);
    }

    private function confirmPage(\App\Platform\Identity\Domain\User $user, string $return, ?string $method, ?string $error = null, int $status = 200): ResponseInterface
    {
        $needPassword = $user->canUsePassword() && !$this->passwordConfirmed();
        $params = ['return' => $return, 'needPassword' => $needPassword, 'mfa' => $user->mfaEnabled, 'error' => $error];
        if (!$needPassword && $user->mfaEnabled) {
            $params += $this->secondFactor->params($user, $method, 'reauth') + [
                'routes' => ['submit' => 'auth.confirm.submit', 'send' => 'auth.confirm.send', 'self' => 'auth.confirm', 'passkey' => 'auth.confirm.passkey', 'eimzo' => 'auth.confirm.eimzo'],
                'selfQuery' => ['return' => $return],
                'extraFields' => '<input type="hidden" name="return" value="' . htmlspecialchars($return, ENT_QUOTES) . '">',
            ];
        }
        $response = $this->responder->render('auth/confirm', $params, 'auth', $status);
        return ($params['selected'] ?? '') === 'eimzo' ? $response->withHeader('X-CSP-Connect', \App\Web\Auth\SecondFactor::EIMZO_CONNECT) : $response;
    }

    private function passwordConfirmed(): bool
    {
        $at = $this->session->get(self::REAUTH_PASSWORD);
        return is_int($at) && $at > time() - 300;
    }

    private function user(): \App\Platform\Identity\Domain\User
    {
        $identity = $this->currentUser->getIdentity();
        assert($identity instanceof Identity);
        return $this->repo->find($identity->user->id) ?? $identity->user;
    }
}
