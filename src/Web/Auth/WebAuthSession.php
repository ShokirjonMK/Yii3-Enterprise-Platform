<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Application\AuthenticationService;
use App\Platform\Identity\Application\LoginResult;
use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Shared\Infrastructure\Context\RequestContext;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/**
 * Interactive (cookie/session) authentication state machine:
 *
 *   password/SSO ok ──► [mfa pending] ──TOTP──► logged in ──► [must change password] ──► [mfa setup] ──► full access
 *
 * The session id is regenerated at every privilege change (fixation protection).
 */
final class WebAuthSession
{
    private const PENDING = 'auth.pending';
    private const GATES = 'auth.gates';
    private const PENDING_TTL = 300;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly CurrentUser $currentUser,
        private readonly SessionRegistry $registry,
        private readonly UserRepository $users,
        private readonly AuthenticationService $auth,
        private readonly RequestContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** Called after the first factor succeeded. Returns the route name to continue with. */
    public function begin(LoginResult $result): string
    {
        if ($result->mfaRequired) {
            $this->session->regenerateID();
            $this->session->set(self::PENDING, [
                'user_id' => $result->user->id,
                'method' => $result->method,
                'at' => time(),
                'must_change' => $result->mustChangePassword,
                'attempts' => 0,
            ]);
            return 'auth.mfa';
        }
        $this->complete($result->user, $result->method, false, $result->mustChangePassword, $result->mfaSetupRequired);
        return $this->nextRoute();
    }

    public function pendingUser(): ?User
    {
        $p = $this->session->get(self::PENDING);
        if (!is_array($p) || ($p['at'] ?? 0) < time() - self::PENDING_TTL) {
            $this->session->remove(self::PENDING);
            return null;
        }
        $user = $this->users->find((string) $p['user_id']);
        return $user !== null && $user->isActive() ? $user : null;
    }

    /** @return int remaining attempts */
    public function registerMfaFailure(): int
    {
        $p = $this->session->get(self::PENDING);
        if (!is_array($p)) {
            return 0;
        }
        $p['attempts'] = ($p['attempts'] ?? 0) + 1;
        if ($p['attempts'] >= 5) {
            $this->session->remove(self::PENDING);
            return 0;
        }
        $this->session->set(self::PENDING, $p);
        return 5 - $p['attempts'];
    }

    public function completePending(): string
    {
        $p = (array) $this->session->get(self::PENDING);
        $this->session->remove(self::PENDING);
        $user = $this->users->find((string) $p['user_id']);
        $result = $this->auth->result($user, (string) $p['method']);
        $this->complete($user, (string) $p['method'], true, (bool) ($p['must_change'] ?? false), false);
        return $this->nextRoute();
    }

    public function complete(User $user, string $method, bool $mfaVerified, bool $mustChange, bool $mfaSetup): void
    {
        $this->session->regenerateID();
        $this->currentUser->login(new Identity($user));
        $this->registry->start((string) $this->session->getId(), $user->id, $method, $this->context->ip(), $this->context->userAgent(), $mfaVerified);
        $gates = [];
        if ($mustChange) {
            $gates[] = 'password';
        }
        if ($mfaSetup) {
            $gates[] = 'mfa_setup';
        }
        $this->session->set(self::GATES, $gates);
        $this->session->set('auth.method', $method);
        $this->context->setUserId($user->id);
        $this->context->setTenantId($user->tenantId);
        $this->auth->recordSuccess($user, $method);
    }

    /** @return list<string> */
    public function gates(): array
    {
        $g = $this->session->get(self::GATES, []);
        return is_array($g) ? array_values($g) : [];
    }

    public function clearGate(string $gate): void
    {
        $this->session->set(self::GATES, array_values(array_diff($this->gates(), [$gate])));
        $this->session->regenerateID();
        $id = (string) $this->session->getId();
        $userId = $this->currentUser->getId();
        if ($userId !== null) {
            // regenerateID changed the id: re-register the session under the new id
            $this->registry->start($id, $userId, (string) $this->session->get('auth.method', 'password'), $this->context->ip(), $this->context->userAgent(), true);
        }
    }

    public function nextRoute(): string
    {
        $gates = $this->gates();
        return match (true) {
            in_array('password', $gates, true) => 'auth.password.change',
            in_array('mfa_setup', $gates, true) => 'auth.mfa.setup',
            default => 'home.redirect',
        };
    }

    public function logout(string $reason = 'logout'): void
    {
        $id = $this->session->getId();
        $userId = $this->currentUser->getId();
        if ($id !== null) {
            $this->registry->revokeCurrent($id, $reason);
        }
        if ($userId !== null) {
            $this->audit->security('auth.logout', 'success', ['reason' => $reason], 'info', $userId);
        }
        $this->currentUser->logout();
        $this->session->destroy();
    }

    public function sessionId(): ?string
    {
        return $this->session->getId();
    }

    public function markReauthenticated(): void
    {
        $id = $this->session->getId();
        if ($id !== null) {
            $this->registry->markReauthenticated($id);
        }
    }

    public function reauthAge(): ?int
    {
        $id = $this->session->getId();
        return $id === null ? null : $this->registry->reauthAge($id);
    }
}
