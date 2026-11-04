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
 * Login gates (forced password change, MFA enrolment) and step-up re-authentication.
 */
final class AccountSecurityController
{
    private const SETUP_SECRET = 'mfa.setup.secret';

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

    public function mfaSetupForm(): ResponseInterface
    {
        $user = $this->user();
        $secret = $this->session->get(self::SETUP_SECRET);
        if (!is_string($secret)) {
            $secret = $this->mfa->generateSecret();
            $this->session->set(self::SETUP_SECRET, $secret);
        }
        $uri = $this->mfa->provisioningUri($secret, $user->username);
        return $this->responder->render('auth/mfa-setup', [
            'qr' => $this->mfa->qrSvg($uri),
            'secret' => $secret,
            'forced' => in_array('mfa_setup', $this->webAuth->gates(), true),
        ], 'auth');
    }

    public function mfaSetupSubmit(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $secret = $this->session->get(self::SETUP_SECRET);
        $code = (string) (((array) $request->getParsedBody())['code'] ?? '');
        if (!is_string($secret) || $this->mfa->verifyWithSecret($secret, $code) === null) {
            $this->responder->error('The code is not valid. Check the time on your device and try again.');
            return $this->responder->redirect('auth.mfa.setup');
        }
        $codes = $this->mfa->enable($user->id, $secret);
        $this->session->remove(self::SETUP_SECRET);
        $this->audit->security('auth.mfa.enabled', 'success', [], 'notice', $user->id, $user->username);
        $this->webAuth->clearGate('mfa_setup');
        $this->webAuth->markReauthenticated();
        return $this->responder->render('auth/recovery-codes', ['codes' => $codes, 'next' => $this->webAuth->nextRoute()], 'auth');
    }

    public function confirmForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->render('auth/confirm', [
            'return' => RequireLogin::safeReturn($request->getQueryParams()['return'] ?? null) ?? '/admin',
            'mfa' => $this->user()->mfaEnabled,
        ], 'auth');
    }

    public function confirmSubmit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $user = $this->user();
        $return = RequireLogin::safeReturn($body['return'] ?? null) ?? '/admin';
        $ok = $user->canUsePassword() ? $this->auth->verifyPassword($user, (string) ($body['password'] ?? '')) : true;
        if ($ok && $user->mfaEnabled) {
            $ok = $this->mfa->verifyForUser($user, (string) ($body['code'] ?? ''));
        }
        if (!$ok || (!$user->canUsePassword() && !$user->mfaEnabled)) {
            $this->audit->security('auth.reauth.failure', 'failure', [], 'notice', $user->id, $user->username);
            return $this->responder->render('auth/confirm', ['return' => $return, 'mfa' => $user->mfaEnabled, 'error' => 'Confirmation failed.'], 'auth', 422);
        }
        $this->webAuth->markReauthenticated();
        $this->audit->security('auth.reauth.success', 'success', [], 'info', $user->id, $user->username);
        $this->responder->success('Identity confirmed. You can now repeat the action.');
        return $this->responder->redirectToUrl($return);
    }

    private function user(): \App\Platform\Identity\Domain\User
    {
        $identity = $this->currentUser->getIdentity();
        assert($identity instanceof Identity);
        return $this->repo->find($identity->user->id) ?? $identity->user;
    }
}
