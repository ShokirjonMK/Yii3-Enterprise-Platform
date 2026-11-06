<?php

declare(strict_types=1);

namespace App\Platform\Access;

use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\ManagerInterface;

/**
 * Server-side authorization and role administration. All decisions are default-deny.
 *
 * Guard rails:
 *  - administrative roles (RbacCatalog::ADMIN_ROLES) can be granted/revoked only by a superadmin,
 *  - the last active superadmin can never lose the role,
 *  - a user cannot change their own roles.
 */
final class AccessService
{
    public function __construct(
        private readonly ManagerInterface $rbac,
        private readonly ItemsStorageInterface $items,
        private readonly \Yiisoft\Rbac\AssignmentsStorageInterface $assignments,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        private readonly RbacCatalog $catalog,
        private readonly \Yiisoft\Db\Connection\ConnectionInterface $db,
    ) {}

    /**
     * Administrative role = a catalog admin role, a role flagged "administrative" in the admin panel
     * (rbac_role_meta.is_admin), or any role whose effective permissions (incl. inherited roles) contain a
     * privileged permission (RbacCatalog::PRIVILEGED_PERMISSIONS). Only a superadmin may grant/revoke/edit such roles.
     */
    public function isAdminRole(string $role): bool
    {
        if (in_array($role, RbacCatalog::ADMIN_ROLES, true)) {
            return true;
        }
        try {
            $flag = (new \Yiisoft\Db\Query\Query($this->db))->select('is_admin')->from('rbac_role_meta')->where(['name' => $role])->scalar();
            if ($flag === true || $flag === 't' || $flag === 1) {
                return true;
            }
            foreach (array_keys($this->rbac->getChildRoles($role)) as $child) {
                if (in_array($child, RbacCatalog::ADMIN_ROLES, true)) {
                    return true;
                }
            }
            return array_intersect(array_keys($this->rbac->getPermissionsByRoleName($role)), RbacCatalog::PRIVILEGED_PERMISSIONS) !== [];
        } catch (\Throwable) {
            return true; // fail closed
        }
    }

    /** @var array<string, array{at: float, perms: array<string, true>}> effective permissions per user (short-lived) */
    private array $permissionCache = [];

    public function can(?string $userId, string $permission): bool
    {
        if ($userId === null) {
            return false;
        }
        try {
            // One RBAC lookup per user instead of one per check (the admin menu alone performs ~30 checks).
            // Rules are not used in the catalog, so the effective permission set is exact. Entries expire after
            // 5 s so long-running workers observe role changes quickly; role changes here clear the cache.
            $entry = $this->permissionCache[$userId] ?? null;
            if ($entry === null || microtime(true) - $entry['at'] > 5) {
                $entry = ['at' => microtime(true), 'perms' => array_fill_keys(array_keys($this->rbac->getPermissionsByUserId($userId)), true)];
                $this->permissionCache[$userId] = $entry;
            }
            return isset($entry['perms'][$permission]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function forget(?string $userId = null): void
    {
        if ($userId === null) {
            $this->permissionCache = [];
        } else {
            unset($this->permissionCache[$userId]);
        }
    }

    public function assert(?string $userId, string $permission): void
    {
        if (!$this->can($userId, $permission)) {
            $this->audit->security('access.denied', 'denied', ['permission' => $permission], 'notice', $userId);
            throw new AccessDeniedException('You do not have permission to perform this action.', $permission);
        }
    }

    public function isSuperadmin(?string $userId): bool
    {
        return $userId !== null && isset($this->rbac->getRolesByUserId($userId)[RbacCatalog::SUPERADMIN]);
    }

    /** @return list<string> */
    public function rolesOf(string $userId): array
    {
        $roles = array_keys($this->rbac->getRolesByUserId($userId));
        sort($roles);
        return $roles;
    }

    /** @return list<string> */
    public function permissionsOf(string $userId): array
    {
        $perms = array_keys($this->rbac->getPermissionsByUserId($userId));
        sort($perms);
        return $perms;
    }

    /**
     * Replaces the direct role set of $userId.
     *
     * @param list<string> $roles
     */
    public function setRoles(string $actorId, string $userId, array $roles): void
    {
        $this->assert($actorId, 'rbac.manage');
        if ($actorId === $userId) {
            throw new AccessDeniedException('You cannot change your own roles.');
        }
        if ($this->users->find($userId) === null) {
            throw new NotFoundException('User not found.');
        }
        foreach ($roles as $role) {
            if ($this->items->getRole($role) === null) {
                throw ValidationException::single('roles', "Unknown role \"$role\".");
            }
        }

        $current = $this->directRoles($userId);
        $add = array_values(array_diff($roles, $current));
        $remove = array_values(array_diff($current, $roles));

        $actorIsSuper = $this->isSuperadmin($actorId);
        foreach ([...$add, ...$remove] as $role) {
            if (!$actorIsSuper && $this->isAdminRole($role)) {
                throw new AccessDeniedException("Only a superadmin can grant or revoke the \"$role\" role.");
            }
        }
        if (in_array(RbacCatalog::SUPERADMIN, $remove, true)
            && $this->users->countActiveWithRole(RbacCatalog::SUPERADMIN, $userId) === 0) {
            throw new ConflictException('The last active superadmin cannot lose the superadmin role.', 'last_superadmin');
        }

        foreach ($add as $role) {
            $this->rbac->assign($role, $userId);
        }
        foreach ($remove as $role) {
            $this->rbac->revoke($role, $userId);
        }
        $this->forget($userId);

        if ($add !== [] || $remove !== []) {
            $this->audit->record(
                'rbac.roles.changed',
                'user',
                $userId,
                ['roles' => $current],
                ['roles' => $roles],
                ['added' => $add, 'removed' => $remove],
                category: 'access',
                severity: array_filter($add, fn(string $r) => $this->isAdminRole($r)) !== [] ? 'warning' : 'notice',
            );
        }
    }

    /** System-level assignment without actor checks (installer, seeders, SSO auto-provisioning). */
    public function assignSystem(string $userId, string $role): void
    {
        if (!isset($this->rbac->getRolesByUserId($userId)[$role])) {
            $this->rbac->assign($role, $userId);
            $this->forget($userId);
            $this->audit->record('rbac.role.assigned', 'user', $userId, null, ['role' => $role], ['by' => 'system'], category: 'access', severity: 'notice');
        }
    }

    /** @return list<string> */
    public function directRoles(string $userId): array
    {
        $direct = array_values(array_filter(
            array_keys($this->assignments->getByUserId($userId)),
            fn(string $name) => $this->items->roleExists($name),
        ));
        sort($direct);
        return $direct;
    }
}
