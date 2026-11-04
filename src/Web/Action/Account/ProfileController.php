<?php

declare(strict_types=1);

namespace App\Web\Action\Account;

use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Application\ApiTokenService;
use App\Platform\Identity\Application\MfaService;
use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Access\AccessService;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use App\Web\Auth\WebAuthSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/**
 * Self-service: profile, sessions/devices, personal API tokens, MFA recovery codes.
 */
final class ProfileController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly UserRepository $users,
        private readonly SessionRegistry $sessions,
        private readonly ApiTokenService $tokens,
        private readonly MfaService $mfa,
        private readonly WebAuthSession $webAuth,
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
        private readonly SessionInterface $session,
    ) {}

    public function show(): ResponseInterface
    {
        $user = $this->user();
        return $this->responder->render('web/account/profile', ['user' => $user, 'roles' => $this->access->rolesOf($user->id)], 'web');
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $b = (array) $request->getParsedBody();
        $errors = [];
        $fullName = trim((string) ($b['full_name'] ?? ''));
        $phone = trim((string) ($b['phone'] ?? ''));
        $locale = (string) ($b['locale'] ?? $user->locale);
        if (mb_strlen($fullName) > 255) {
            $errors['full_name'][] = 'Name is too long.';
        }
        if ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) {
            $errors['phone'][] = 'Invalid phone number.';
        }
        if (!in_array($locale, ['uz', 'ru', 'en'], true)) {
            $errors['locale'][] = 'Unsupported language.';
        }
        if ($errors !== []) {
            return $this->responder->render('web/account/profile', ['user' => $user, 'roles' => $this->access->rolesOf($user->id), 'errors' => $errors], 'web', 422);
        }
        $this->users->update($user->id, ['full_name' => $fullName, 'phone' => $phone === '' ? null : $phone, 'locale' => $locale]);
        $this->session->set('locale', $locale);
        $this->audit->record('user.profile.updated', 'user', $user->id, ['full_name' => $user->fullName, 'phone' => $user->phone, 'locale' => $user->locale], ['full_name' => $fullName, 'phone' => $phone, 'locale' => $locale]);
        $this->responder->success('Profile saved.');
        return $this->responder->redirect('web.profile');
    }

    public function security(): ResponseInterface
    {
        $user = $this->user();
        $newToken = $this->session->get('new_token');
        $this->session->remove('new_token');
        return $this->responder->render('web/account/security', [
            'user' => $user,
            'sessions' => $this->sessions->activeForUser($user->id),
            'currentSession' => SessionRegistry::hash((string) $this->webAuth->sessionId()),
            'tokens' => $this->tokens->listForUser($user->id),
            'newToken' => $newToken,
        ], 'web');
    }

    public function revokeSession(CurrentRoute $route): ResponseInterface
    {
        $user = $this->user();
        $id = (string) $route->getArgument('id');
        $own = array_filter($this->sessions->activeForUser($user->id), static fn($s) => $s['id'] === $id);
        if ($own !== []) {
            $this->sessions->revoke($id, 'revoked_by_user');
            $this->audit->security('session.revoked', 'success', ['session' => substr($id, 0, 12)], 'info', $user->id);
            $this->responder->success('Session terminated.');
        }
        return $this->responder->redirect('web.security');
    }

    public function revokeOtherSessions(): ResponseInterface
    {
        $user = $this->user();
        $n = $this->sessions->revokeAllForUser($user->id, 'revoked_by_user', $this->webAuth->sessionId());
        $this->audit->security('session.revoked_others', 'success', ['count' => $n], 'info', $user->id);
        $this->responder->success('Other sessions terminated.');
        return $this->responder->redirect('web.security');
    }

    public function createToken(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $b = (array) $request->getParsedBody();
        $name = trim((string) ($b['name'] ?? ''));
        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) ($b['scopes'] ?? '*')))));
        try {
            if ($name === '' || mb_strlen($name) > 128) {
                throw ValidationException::single('name', 'Token name is required (max 128 characters).');
            }
            $token = $this->tokens->issuePersonal($user->id, $name, $scopes ?: ['*'], (int) ($b['ttl_days'] ?? 90));
        } catch (ValidationException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('web.security');
        }
        $this->audit->security('api_token.created', 'success', ['name' => $name, 'scopes' => $scopes], 'notice', $user->id);
        $this->session->set('new_token', $token['token']);
        $this->responder->success('Token created. Copy it now — it will not be shown again.');
        return $this->responder->redirect('web.security');
    }

    public function revokeToken(CurrentRoute $route): ResponseInterface
    {
        $user = $this->user();
        if ($this->tokens->revoke((string) $route->getArgument('id'), $user->id)) {
            $this->audit->security('api_token.revoked', 'success', [], 'info', $user->id);
            $this->responder->success('Token revoked.');
        }
        return $this->responder->redirect('web.security');
    }

    public function regenerateRecoveryCodes(): ResponseInterface
    {
        $user = $this->user();
        if (!$user->mfaEnabled) {
            return $this->responder->redirect('web.security');
        }
        $codes = $this->mfa->regenerateRecoveryCodes($user->id);
        $this->audit->security('auth.mfa.codes_regenerated', 'success', [], 'notice', $user->id);
        return $this->responder->render('auth/recovery-codes', ['codes' => $codes, 'next' => 'web.security'], 'auth');
    }

    public function disableMfa(): ResponseInterface
    {
        $user = $this->user();
        $roles = $this->access->rolesOf($user->id);
        if ($user->mfaRequired || array_intersect($roles, ['superadmin', 'platform_admin', 'security_admin', 'devops_sre']) !== []) {
            $this->responder->error('Two-factor authentication is mandatory for your account.');
            return $this->responder->redirect('web.security');
        }
        $this->mfa->disable($user->id);
        $this->audit->security('auth.mfa.disabled', 'success', [], 'warning', $user->id);
        $this->responder->success('Two-factor authentication disabled.');
        return $this->responder->redirect('web.security');
    }

    private function user(): User
    {
        $identity = $this->currentUser->getIdentity();
        assert($identity instanceof Identity);
        return $this->users->find($identity->user->id) ?? $identity->user;
    }
}
