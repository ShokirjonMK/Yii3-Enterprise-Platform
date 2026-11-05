<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use App\Platform\Access\AccessService;
use App\Platform\Access\RbacCatalog;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\AuthPolicy;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Domain\UserStatus;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Page;
use SensitiveParameter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Security\PasswordHasher;

/**
 * User administration use cases shared by Admin UI, REST API and console.
 * Tenant isolation: actors without `tenants.cross` can only see/manage users of their own tenant.
 */
final class UserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
        private readonly SessionRegistry $sessions,
        private readonly ApiTokenService $tokens,
        private readonly int $passwordHistory = 5,
    ) {}

    public function list(User $actor, array $filters, int $page, int $perPage, ?string $sort): Page
    {
        $this->access->assert($actor->id, 'users.view');
        return $this->users->search($filters, $page, $perPage, $sort, $this->tenantRestriction($actor));
    }

    public function get(User $actor, string $id): User
    {
        $this->access->assert($actor->id, 'users.view');
        return $this->load($actor, $id);
    }

    /**
     * @return array{user:User, temporaryPassword:?string}
     */
    public function create(User $actor, array $input): array
    {
        $this->access->assert($actor->id, 'users.manage');
        $data = $this->validate($input, true);
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            $data['tenant_id'] = $restriction;
        }

        $temporary = null;
        if ($data['auth_policy'] !== AuthPolicy::SSO_ONLY) {
            $password = (string) ($input['password'] ?? '');
            if ($password === '') {
                $password = $temporary = PasswordPolicy::generate();
            } else {
                $this->policy->assertValid($password, $data['username']);
            }
            $data['password_hash'] = $this->hasher->hash($password);
            $data['password_changed_at'] = new Expression('now()');
            $data['must_change_password'] = true;
        }

        $id = $this->db->transaction(function () use ($data, $input): string {
            $id = $this->users->insert($data);
            if (isset($data['password_hash'])) {
                $this->users->addPasswordHistory($id, $data['password_hash']);
            }
            $this->access->assignSystem($id, 'user');
            $user = $this->users->find($id);
            $this->audit->record('user.created', 'user', $id, null, $user?->toPublicArray(), category: 'data');
            return $id;
        });

        return ['user' => $this->users->find($id), 'temporaryPassword' => $temporary];
    }

    public function update(User $actor, string $id, array $input, ?int $expectedVersion = null): User
    {
        $this->access->assert($actor->id, 'users.manage');
        $before = $this->load($actor, $id);
        $data = $this->validate($input, false);
        if ($this->tenantRestriction($actor) !== null) {
            unset($data['tenant_id']);
        }
        if (($data['auth_policy'] ?? null) === AuthPolicy::SSO_ONLY && $before->passwordHash !== null) {
            $data['password_hash'] = null; // SSO-only accounts must not keep a usable password
        }
        if (isset($data['status']) && $data['status'] !== UserStatus::ACTIVE) {
            $this->guardDeactivation($actor, $before);
        }

        $this->db->transaction(function () use ($id, $data, $expectedVersion, $before): void {
            $this->users->update($id, $data, $expectedVersion);
            $after = $this->users->find($id);
            $this->audit->record('user.updated', 'user', $id, $before->toPublicArray(), $after?->toPublicArray());
            if (isset($data['status']) && $data['status'] !== UserStatus::ACTIVE) {
                $this->sessions->revokeAllForUser($id, 'status_' . $data['status']);
                $this->tokens->revokeAllForUser($id);
            }
        });
        return $this->users->find($id);
    }

    public function setStatus(User $actor, string $id, string $status): User
    {
        if (!in_array($status, UserStatus::ALL, true)) {
            throw ValidationException::single('status', 'Unknown status.');
        }
        return $this->update($actor, $id, ['status' => $status]);
    }

    /** Soft delete (archive). Data is kept for audit; the username becomes available again. */
    public function archive(User $actor, string $id): void
    {
        $this->access->assert($actor->id, 'users.manage');
        $user = $this->load($actor, $id);
        $this->guardDeactivation($actor, $user);
        $this->db->transaction(function () use ($user): void {
            $this->users->update($user->id, ['status' => UserStatus::ARCHIVED, 'deleted_at' => new Expression('now()')]);
            $this->sessions->revokeAllForUser($user->id, 'archived');
            $this->tokens->revokeAllForUser($user->id);
            $this->audit->record('user.archived', 'user', $user->id, $user->toPublicArray(), null, severity: 'notice');
        });
    }

    /** Admin-initiated password reset → returns a one-time temporary password. */
    public function resetPassword(User $actor, string $id): string
    {
        $this->access->assert($actor->id, 'users.manage');
        $user = $this->load($actor, $id);
        if ($user->authPolicy === AuthPolicy::SSO_ONLY) {
            throw new ConflictException('This account signs in via SSO only and has no password.', 'sso_only');
        }
        if ($this->access->isSuperadmin($user->id) && !$this->access->isSuperadmin($actor->id)) {
            throw new AccessDeniedException('Only a superadmin can reset a superadmin password.');
        }
        $temporary = PasswordPolicy::generate();
        $hash = $this->hasher->hash($temporary);
        $this->db->transaction(function () use ($user, $hash): void {
            $this->users->update($user->id, ['password_hash' => $hash, 'must_change_password' => true, 'password_changed_at' => new Expression('now()'), 'failed_logins' => 0, 'locked_until' => null]);
            $this->users->addPasswordHistory($user->id, $hash);
            $this->sessions->revokeAllForUser($user->id, 'password_reset');
            $this->audit->record('user.password.reset', 'user', $user->id, severity: 'notice', category: 'security');
        });
        return $temporary;
    }

    public function changeOwnPassword(User $user, #[SensitiveParameter] string $current, #[SensitiveParameter] string $new, ?string $keepSessionId = null): void
    {
        if (!$user->canUsePassword()) {
            throw new ConflictException('This account signs in via SSO only.', 'sso_only');
        }
        if (!$this->hasher->validate($current, (string) $user->passwordHash)) {
            $this->audit->security('user.password.change', 'failure', ['reason' => 'bad_current'], 'notice', $user->id, $user->username);
            throw ValidationException::single('current_password', 'Current password is incorrect.');
        }
        $this->policy->assertValid($new, $user->username, 'new_password');
        foreach ($this->users->passwordHistory($user->id, $this->passwordHistory) as $old) {
            if ($this->hasher->validate($new, (string) $old)) {
                throw ValidationException::single('new_password', sprintf('You cannot reuse any of your last %d passwords.', $this->passwordHistory));
            }
        }
        $hash = $this->hasher->hash($new);
        $this->db->transaction(function () use ($user, $hash, $keepSessionId): void {
            $this->users->update($user->id, ['password_hash' => $hash, 'must_change_password' => false, 'password_changed_at' => new Expression('now()')]);
            $this->users->addPasswordHistory($user->id, $hash);
            $this->sessions->revokeAllForUser($user->id, 'password_changed', $keepSessionId);
            $this->audit->security('user.password.changed', 'success', [], 'notice', $user->id, $user->username);
        });
    }

    public function unlock(User $actor, string $id): void
    {
        $this->access->assert($actor->id, 'users.manage');
        $user = $this->load($actor, $id);
        $this->users->update($user->id, ['failed_logins' => 0, 'locked_until' => null]);
        $this->audit->record('user.unlocked', 'user', $user->id, category: 'security');
    }

    /**
     * Accounts holding administrative roles (RbacCatalog::ADMIN_ROLES) may only be modified by a superadmin
     * (prevents a tenant/business admin from taking over a privileged account via e-mail/password/session changes).
     */
    public function assertMayAdminister(User $actor, User $target): void
    {
        if ($this->access->isSuperadmin($actor->id)) {
            return;
        }
        // administrative = catalog admin roles and custom roles flagged/holding privileged permissions
        if (array_filter($this->access->rolesOf($target->id), fn(string $r) => $this->access->isAdminRole($r)) !== []) {
            throw new AccessDeniedException('Only a superadmin can modify an administrative account.');
        }
    }

    /** Active (not revoked, not expired) sessions of a user visible to the actor. */
    public function activeSessions(User $actor, string $id): array
    {
        $this->access->assert($actor->id, 'users.view');
        return $this->sessions->activeForUser($this->load($actor, $id)->id);
    }

    /** Terminates one session of a user; $sessionHash is the SHA-256 id from user_sessions. */
    public function revokeSession(User $actor, string $id, string $sessionHash): void
    {
        $this->access->assert($actor->id, 'sessions.revoke');
        $user = $this->load($actor, $id);
        $this->assertMayAdminister($actor, $user);
        $own = array_filter($this->sessions->activeForUser($user->id), static fn(array $s) => hash_equals((string) $s['id'], $sessionHash));
        if ($own === []) {
            throw new NotFoundException('Session not found.');
        }
        $this->db->transaction(function () use ($user, $sessionHash): void {
            $this->sessions->revoke($sessionHash, 'revoked_by_admin');
            $this->audit->record('session.revoked_by_admin', 'user', $user->id, null, null, ['session' => substr($sessionHash, 0, 12)], category: 'security', severity: 'notice');
        });
    }

    public function revokeAllSessions(User $actor, string $id): int
    {
        $this->access->assert($actor->id, 'sessions.revoke');
        $user = $this->load($actor, $id);
        $this->assertMayAdminister($actor, $user);
        return $this->db->transaction(function () use ($user): int {
            $n = $this->sessions->revokeAllForUser($user->id, 'revoked_by_admin');
            $this->audit->record('session.revoked_all_by_admin', 'user', $user->id, null, null, ['count' => $n], category: 'security', severity: 'notice');
            return $n;
        });
    }

    /** Linked external (SSO) identities of a user visible to the actor. */
    public function linkedIdentities(User $actor, string $id): array
    {
        $this->access->assert($actor->id, 'users.view');
        return $this->users->identities($this->load($actor, $id)->id);
    }

    public function load(User $actor, string $id): User
    {
        $user = $this->users->find($id);
        if ($user === null || $user->deletedAt !== null) {
            throw new NotFoundException('User not found.');
        }
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null && $user->tenantId !== $restriction) {
            // IDOR protection: foreign-tenant objects are reported as non-existent
            throw new NotFoundException('User not found.');
        }
        return $user;
    }

    /** Tenant the actor is confined to, or null when the actor may act across tenants. */
    public function tenantRestriction(User $actor): ?string
    {
        if ($this->access->can($actor->id, 'tenants.cross')) {
            return null;
        }
        return $actor->tenantId ?? '00000000-0000-0000-0000-000000000000';
    }

    private function guardDeactivation(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new ConflictException('You cannot deactivate your own account.', 'self_deactivation');
        }
        if ($this->access->isSuperadmin($target->id)) {
            if (!$this->access->isSuperadmin($actor->id)) {
                throw new AccessDeniedException('Only a superadmin can deactivate a superadmin.');
            }
            if ($this->users->countActiveWithRole(RbacCatalog::SUPERADMIN, $target->id) === 0) {
                throw new ConflictException('The last active superadmin cannot be deactivated.', 'last_superadmin');
            }
        }
    }

    private function validate(array $in, bool $creating): array
    {
        $errors = [];
        $data = [];
        $str = static fn(string $k) => isset($in[$k]) ? trim((string) $in[$k]) : null;

        if ($creating || array_key_exists('username', $in)) {
            $username = $str('username') ?? '';
            if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username)) {
                $errors['username'][] = 'Username must be 3–64 characters: letters, digits, . _ @ -';
            }
            $data['username'] = $username;
        }
        if (array_key_exists('email', $in)) {
            $email = $str('email');
            if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'][] = 'Invalid e-mail address.';
            }
            $data['email'] = $email === '' ? null : $email;
        }
        if (array_key_exists('phone', $in)) {
            $phone = $str('phone');
            if ($phone !== null && $phone !== '' && !preg_match('/^\+?[0-9 ()-]{7,20}$/', $phone)) {
                $errors['phone'][] = 'Invalid phone number.';
            }
            $data['phone'] = $phone === '' ? null : $phone;
        }
        if ($creating || array_key_exists('full_name', $in)) {
            $name = $str('full_name') ?? '';
            if (mb_strlen($name) > 255) {
                $errors['full_name'][] = 'Name is too long.';
            }
            $data['full_name'] = $name;
        }
        if ($creating || array_key_exists('auth_policy', $in)) {
            $policy = $str('auth_policy') ?: AuthPolicy::ANY;
            if (!in_array($policy, AuthPolicy::ALL, true)) {
                $errors['auth_policy'][] = 'Unknown authentication policy.';
            }
            $data['auth_policy'] = $policy;
        }
        if (array_key_exists('status', $in)) {
            $status = $str('status');
            if (!in_array($status, UserStatus::ALL, true)) {
                $errors['status'][] = 'Unknown status.';
            }
            $data['status'] = $status;
        }
        foreach (['tenant_id', 'organization_id'] as $fk) {
            if (array_key_exists($fk, $in)) {
                $v = $str($fk);
                if ($v !== null && $v !== '' && !UserRepository::isUuid($v)) {
                    $errors[$fk][] = 'Invalid reference.';
                }
                $data[$fk] = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('locale', $in)) {
            $loc = $str('locale');
            if (!in_array($loc, ['uz', 'ru', 'en'], true)) {
                $errors['locale'][] = 'Unsupported language.';
            }
            $data['locale'] = $loc;
        }
        if (array_key_exists('mfa_required', $in)) {
            $data['mfa_required'] = filter_var($in['mfa_required'], FILTER_VALIDATE_BOOLEAN);
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $data;
    }
}
