<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Shared\Domain\Exception\TooManyRequestsException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Context\RequestContext;
use SensitiveParameter;
use Yiisoft\Security\PasswordHasher;

/**
 * First authentication factor (local password) with throttling, lockout, constant-time behaviour
 * for unknown users and transparent hash upgrades. Used by Web login and the API token endpoint.
 */
final class AuthenticationService
{
    public const GENERIC_ERROR = 'Invalid username or password.';

    /** Pre-computed Argon2id hash used to equalise timing for unknown users. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZWlkUE9MR2tLV2FpOFEuZA$72jelz29bM0ZmBrDCoNxnWLVG3QTRVYkCIU4F3aItwM';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
        private readonly RequestContext $context,
        private readonly \App\Platform\Identity\Mfa\MfaManager $mfa,
    ) {}

    public function authenticate(string $login, #[SensitiveParameter] string $password): LoginResult
    {
        $login = trim($login);
        $ip = $this->context->ip();
        if ($login === '' || $password === '') {
            throw ValidationException::single('login', self::GENERIC_ERROR);
        }

        $wait = $this->throttle->check($login, $ip);
        if ($wait !== null) {
            $this->throttle->record($login, $ip, false, 'throttled', userAgent: $this->context->userAgent());
            $this->audit->security('auth.login.throttled', 'denied', ['login' => $login], 'warning');
            throw new TooManyRequestsException('Too many login attempts. Please try again later.', $wait);
        }

        $user = $this->users->findByLogin($login);
        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);
            $this->fail($login, null, 'unknown_user');
        }

        if ($user->isLocked()) {
            $this->throttle->record($login, $ip, false, 'locked', user: $user, userAgent: $this->context->userAgent());
            $this->audit->security('auth.login.locked', 'denied', ['login' => $login], 'warning', $user->id, $user->username);
            throw new TooManyRequestsException('The account is temporarily locked after repeated failed attempts.', max(60, $user->lockedUntil->getTimestamp() - time()));
        }

        if (!$user->canUsePassword()) {
            password_verify($password, self::DUMMY_HASH);
            $this->fail($login, $user, 'password_not_allowed');
        }

        if (!$this->hasher->validate($password, (string) $user->passwordHash)) {
            $locked = $this->throttle->registerFailure($user->id);
            if ($locked) {
                $this->audit->security('auth.account.locked', 'denied', ['login' => $login], 'warning', $user->id, $user->username);
            }
            $this->fail($login, $user, 'bad_password');
        }

        if (!$user->isActive()) {
            $this->fail($login, $user, 'inactive_' . $user->status);
        }
        if (!$this->users->isTenantActive($user->tenantId)) {
            $this->fail($login, $user, 'tenant_inactive');
        }

        if (password_needs_rehash((string) $user->passwordHash, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1])) {
            $this->users->update($user->id, ['password_hash' => $this->hasher->hash($password)]);
        }

        return $this->result($user, 'password');
    }

    /** Builds the second-step requirements for a user who passed the first factor (password or SSO). */
    public function result(User $user, string $method): LoginResult
    {
        // who must use 2FA and with which methods: per-role policies (Admin → Authentication → Two-factor)
        return new LoginResult(
            $user,
            $method,
            mfaRequired: $user->mfaEnabled,
            mfaSetupRequired: $this->mfa->setupRequired($user),
            mustChangePassword: $method === 'password' && $user->mustChangePassword,
        );
    }

    /** @param string|null $mfaMethod second factor used (totp, webauthn, email, …, trusted_device), null = none */
    public function recordSuccess(User $user, string $method, ?string $mfaMethod = null): void
    {
        $this->throttle->registerSuccess($user->id, $this->context->ip());
        $this->throttle->record($user->username, $this->context->ip(), true, 'ok', $method, $user, $this->context->userAgent(), $mfaMethod);
        $this->audit->security('auth.login.success', 'success', array_filter(['method' => $method, 'mfa' => $mfaMethod]), 'info', $user->id, $user->username);
    }

    public function findForBreakGlass(string $login): ?User
    {
        return $this->users->findByLogin($login);
    }

    public function recordMfaFailure(User $user, ?string $mfaMethod = null): void
    {
        $this->throttle->record($user->username, $this->context->ip(), false, 'bad_mfa', 'mfa', $user, $this->context->userAgent(), $mfaMethod);
        $this->audit->security('auth.mfa.failure', 'failure', [], 'notice', $user->id, $user->username);
    }

    public function verifyPassword(User $user, #[SensitiveParameter] string $password): bool
    {
        return $user->passwordHash !== null && $this->hasher->validate($password, $user->passwordHash);
    }

    private function fail(string $login, ?User $user, string $reason): never
    {
        $this->throttle->record($login, $this->context->ip(), false, $reason, user: $user, userAgent: $this->context->userAgent());
        $this->audit->security('auth.login.failure', 'failure', ['login' => $login, 'reason' => $reason], 'notice', $user?->id, $user?->username);
        throw ValidationException::single('login', self::GENERIC_ERROR);
    }
}
