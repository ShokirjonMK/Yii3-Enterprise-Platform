<?php

declare(strict_types=1);

namespace App\Platform\Access;

use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Rbac\Permission;
use Yiisoft\Rbac\Role;

/**
 * Dynamic role & permission administration (Admin → 04 RBAC). The RBAC storage (yii_rbac_*) is the source of
 * truth at runtime; catalog files define "system" roles/permissions, everything else is "custom".
 *
 * Guard rails (in addition to rbac.manage + step-up re-auth on the routes):
 *  - the superadmin role is read-only (always every permission),
 *  - system roles and permissions cannot be deleted; their link edits are stored as overrides so rbac:sync keeps them,
 *  - administrative roles (AccessService::isAdminRole) can be created/edited/deleted only by a superadmin,
 *    and only a superadmin may make a role administrative (flag or privileged permission),
 *  - a non-superadmin may only grant permissions (directly or via a child role) that they hold themselves,
 *  - inheritance cycles are refused.
 *
 * Author: ShokirjonMK
 */
final class RoleManager
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';
    public const PERMISSION_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/';

    public function __construct(
        private readonly ManagerInterface $rbac,
        private readonly ItemsStorageInterface $items,
        private readonly AccessService $access,
        private readonly RbacCatalog $catalog,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @return array<string, array{name:string, description:string, system:bool, admin:bool, flagged:bool,
     *     permissions:list<string>, children:list<string>, effective:int}>
     */
    public function roles(): array
    {
        $flags = $this->flags();
        $out = [];
        $roles = $this->items->getRoles();
        ksort($roles);
        // catalog order first (user → superadmin), then custom roles alphabetically
        $ordered = array_merge(array_intersect_key(array_flip(array_keys($this->catalog->roles())), $roles), $roles);
        foreach (array_keys($ordered) as $name) {
            $role = $roles[$name];
            [$perms, $children] = $this->directLinks($name);
            $out[$name] = [
                'name' => $name,
                'description' => $role->getDescription(),
                'system' => $this->catalog->isSystemRole($name),
                'admin' => $this->access->isAdminRole($name),
                'flagged' => $flags[$name] ?? false,
                'permissions' => $perms,
                'children' => $children,
                'effective' => count($this->rbac->getPermissionsByRoleName($name)),
            ];
        }
        return $out;
    }

    /** @return array{name:string, description:string, system:bool, admin:bool, flagged:bool, permissions:list<string>, children:list<string>, effective:int} */
    public function role(string $name): array
    {
        return $this->roles()[$name] ?? throw new NotFoundException('Role not found.');
    }

    /** @return list<string> effective permissions incl. inherited roles */
    public function effective(string $role): array
    {
        $p = array_keys($this->rbac->getPermissionsByRoleName($role));
        sort($p);
        return $p;
    }

    /**
     * @return array<string, array{description:string, system:bool, privileged:bool, roles:list<string>}>
     */
    public function permissions(): array
    {
        $grants = [];
        foreach ((new Query($this->db))->select(['c.parent', 'c.child'])->from(['c' => 'yii_rbac_item_child'])
            ->innerJoin(['i' => 'yii_rbac_item'], 'i.name = c.child')->where(['i.type' => 'permission'])->orderBy('c.parent')->all() as $r) {
            $grants[(string) $r['child']][] = (string) $r['parent'];
        }
        $out = [];
        $perms = $this->items->getPermissions();
        ksort($perms);
        foreach ($perms as $name => $perm) {
            $out[$name] = [
                'description' => $perm->getDescription(),
                'system' => $this->catalog->isSystemPermission($name),
                'privileged' => in_array($name, RbacCatalog::PRIVILEGED_PERMISSIONS, true),
                'roles' => $grants[$name] ?? [],
            ];
        }
        return $out;
    }

    public function createRole(string $actorId, string $name, string $description, bool $isAdmin = false, ?string $cloneFrom = null): void
    {
        $this->access->assert($actorId, 'rbac.manage');
        $name = trim($name);
        $description = trim($description);
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw ValidationException::single('name', 'Role name: 2–64 characters, lowercase latin letters, digits and "_", starting with a letter.');
        }
        if (mb_strlen($description) > 500) {
            throw ValidationException::single('description', 'Description is too long (max 500 characters).');
        }
        if ($this->items->exists($name)) {
            throw ValidationException::single('name', "Role or permission \"$name\" already exists.");
        }
        $perms = $children = [];
        if ($cloneFrom !== null && $cloneFrom !== '') {
            if ($this->items->getRole($cloneFrom) === null) {
                throw ValidationException::single('clone_from', 'Role to copy was not found.');
            }
            [$perms, $children] = $this->directLinks($cloneFrom);
            if ($cloneFrom === RbacCatalog::SUPERADMIN) {
                $perms = array_keys($this->items->getPermissions());
                $children = [];
            }
        }
        $super = $this->access->isSuperadmin($actorId);
        if ($isAdmin && !$super) {
            throw new AccessDeniedException('Only a superadmin can create administrative roles.');
        }
        $this->assertMayGrant($actorId, $super, $perms, $children);

        $this->db->transaction(function () use ($actorId, $name, $description, $isAdmin, $perms, $children, $cloneFrom): void {
            $this->rbac->addRole((new Role($name))->withDescription($description));
            foreach ([...$perms, ...$children] as $child) {
                $this->rbac->addChild($name, $child);
            }
            $this->db->createCommand()->insert('rbac_role_meta', ['name' => $name, 'is_admin' => $isAdmin, 'created_by' => $actorId])->execute();
            $this->audit->record('rbac.role.created', 'rbac_role', $name, null,
                ['description' => $description, 'is_admin' => $isAdmin, 'permissions' => $perms, 'children' => $children],
                ['clone_from' => $cloneFrom], category: 'access', severity: $isAdmin ? 'warning' : 'notice', actorId: $actorId);
        });
        $this->access->forget();
    }

    /**
     * Replaces the direct permissions and child roles of $name (and the description of a custom role / admin flag).
     *
     * @param list<string> $permissions
     * @param list<string> $children
     */
    public function updateRole(string $actorId, string $name, array $permissions, array $children, ?string $description = null, ?bool $isAdmin = null): void
    {
        $this->access->assert($actorId, 'rbac.manage');
        $role = $this->items->getRole($name) ?? throw new NotFoundException('Role not found.');
        if ($name === RbacCatalog::SUPERADMIN) {
            throw new ConflictException('The superadmin role always has every permission and cannot be edited.', 'superadmin_readonly');
        }
        $super = $this->access->isSuperadmin($actorId);
        if (!$super && $this->access->isAdminRole($name)) {
            throw new AccessDeniedException('Only a superadmin can edit administrative roles.');
        }
        $system = $this->catalog->isSystemRole($name);
        $permissions = array_values(array_unique(array_filter(array_map('strval', $permissions), static fn(string $p) => $p !== '')));
        $children = array_values(array_unique(array_filter(array_map('strval', $children), static fn(string $c) => $c !== '')));
        foreach ($permissions as $p) {
            if ($this->items->getPermission($p) === null) {
                throw ValidationException::single('permissions', "Unknown permission \"$p\".");
            }
        }
        foreach ($children as $c) {
            if ($c === $name || $this->items->getRole($c) === null) {
                throw ValidationException::single('children', "Unknown or invalid child role \"$c\".");
            }
        }
        $flags = $this->flags();
        if ($isAdmin !== null && $isAdmin !== ($flags[$name] ?? false) && !$super) {
            throw new AccessDeniedException('Only a superadmin can change the administrative flag.');
        }
        if ($description !== null && mb_strlen(trim($description)) > 500) {
            throw ValidationException::single('description', 'Description is too long (max 500 characters).');
        }

        [$curPerms, $curChildren] = $this->directLinks($name);
        $current = [...$curPerms, ...$curChildren];
        $wanted = [...$permissions, ...$children];
        $add = array_values(array_diff($wanted, $current));
        $remove = array_values(array_diff($current, $wanted));
        $this->assertMayGrant($actorId, $super, array_values(array_intersect($add, $permissions)), array_values(array_intersect($add, $children)));
        foreach (array_intersect($add, $children) as $c) {
            if (!$this->rbac->canAddChild($name, $c)) {
                throw ValidationException::single('children', "Role \"$c\" cannot inherit into \"$name\": it would create a cycle.");
            }
        }

        $before = ['permissions' => $curPerms, 'children' => $curChildren, 'description' => $role->getDescription(), 'is_admin' => $flags[$name] ?? false];
        $this->db->transaction(function () use ($actorId, $name, $role, $system, $add, $remove, $permissions, $children, $description, $isAdmin, $before, $super): void {
            foreach ($remove as $child) {
                $this->rbac->removeChild($name, $child);
            }
            foreach ($add as $child) {
                $this->rbac->addChild($name, $child);
            }
            if ($system) {
                $this->recordOverrides($actorId, $name, $add, $remove);
            }
            $newDescription = $before['description'];
            if (!$system && $description !== null && trim($description) !== $before['description']) {
                $newDescription = trim($description);
                $this->rbac->updateRole($name, $role->withDescription($newDescription)->withUpdatedAt(time()));
            }
            $newFlag = $isAdmin ?? $before['is_admin'];
            $this->db->createCommand()->upsert('rbac_role_meta',
                ['name' => $name, 'is_admin' => $newFlag, 'created_by' => $actorId],
                ['is_admin' => $newFlag, 'updated_at' => new Expression('now()')])->execute();

            $after = ['permissions' => $permissions, 'children' => $children, 'description' => $newDescription, 'is_admin' => $newFlag];
            if ($add !== [] || $remove !== []) {
                $this->audit->record('rbac.role.links_changed', 'rbac_role', $name,
                    ['permissions' => $before['permissions'], 'children' => $before['children']],
                    ['permissions' => $permissions, 'children' => $children],
                    ['added' => $add, 'removed' => $remove, 'system' => $system],
                    category: 'access', severity: $super && $this->access->isAdminRole($name) ? 'warning' : 'notice', actorId: $actorId);
            }
            if ($newDescription !== $before['description'] || $newFlag !== $before['is_admin']) {
                $this->audit->record('rbac.role.updated', 'rbac_role', $name,
                    ['description' => $before['description'], 'is_admin' => $before['is_admin']],
                    ['description' => $newDescription, 'is_admin' => $newFlag], [],
                    category: 'access', severity: $newFlag ? 'warning' : 'notice', actorId: $actorId);
            }
        });
        $this->access->forget();
    }

    /** @return int number of user assignments removed together with the role */
    public function deleteRole(string $actorId, string $name): int
    {
        $this->access->assert($actorId, 'rbac.manage');
        $this->items->getRole($name) ?? throw new NotFoundException('Role not found.');
        if ($this->catalog->isSystemRole($name)) {
            throw new ConflictException('System roles (declared in the catalog) cannot be deleted.', 'system_role');
        }
        if (!$this->access->isSuperadmin($actorId) && $this->access->isAdminRole($name)) {
            throw new AccessDeniedException('Only a superadmin can delete administrative roles.');
        }
        $assigned = (int) (new Query($this->db))->from('yii_rbac_assignment')->where(['item_name' => $name])->count();
        [$perms, $children] = $this->directLinks($name);
        $parents = array_keys($this->items->getParents($name));
        $this->db->transaction(function () use ($actorId, $name, $assigned, $perms, $children, $parents): void {
            $this->rbac->removeRole($name); // also removes its links and assignments
            $this->db->createCommand()->delete('yii_rbac_assignment', ['item_name' => $name])->execute();
            $this->db->createCommand()->delete('rbac_role_meta', ['name' => $name])->execute();
            $this->db->createCommand()->delete('rbac_link_overrides', ['or', ['parent' => $name], ['child' => $name]])->execute();
            $this->audit->record('rbac.role.deleted', 'rbac_role', $name, ['permissions' => $perms, 'children' => $children, 'parents' => $parents], null,
                ['assignments_removed' => $assigned], category: 'access', severity: 'warning', actorId: $actorId);
        });
        $this->access->forget();
        return $assigned;
    }

    /** Drops the admin-panel overrides of a system role and re-applies the catalog definition. */
    public function resetRole(string $actorId, string $name, RbacSynchronizer $sync): int
    {
        $this->access->assert($actorId, 'rbac.manage');
        if (!$this->catalog->isSystemRole($name)) {
            throw new ConflictException('Only system roles have catalog defaults.', 'custom_role');
        }
        if (!$this->access->isSuperadmin($actorId) && $this->access->isAdminRole($name)) {
            throw new AccessDeniedException('Only a superadmin can edit administrative roles.');
        }
        return $this->db->transaction(function () use ($actorId, $name, $sync): int {
            $n = $this->db->createCommand()->delete('rbac_link_overrides', ['parent' => $name])->execute();
            $stats = $sync->sync();
            $this->audit->record('rbac.role.reset', 'rbac_role', $name, null, null, ['overrides_removed' => $n] + $stats,
                category: 'access', severity: 'notice', actorId: $actorId);
            $this->access->forget();
            return $n;
        });
    }

    /** @return list<array{child:string, op:string}> */
    public function overridesOf(string $role): array
    {
        return array_map(static fn(array $r) => ['child' => (string) $r['child'], 'op' => (string) $r['op']],
            (new Query($this->db))->select(['child', 'op'])->from('rbac_link_overrides')->where(['parent' => $role])->orderBy('child')->all());
    }

    public function createPermission(string $actorId, string $name, string $description): void
    {
        $this->access->assert($actorId, 'rbac.manage');
        $name = trim($name);
        $description = trim($description);
        if (!preg_match(self::PERMISSION_PATTERN, $name) || strlen($name) > 126) {
            throw ValidationException::single('name', 'Permission name: dotted lowercase, e.g. "reports.export".');
        }
        if ($description === '' || mb_strlen($description) > 500) {
            throw ValidationException::single('description', 'Description is required (max 500 characters).');
        }
        if ($this->items->exists($name)) {
            throw ValidationException::single('name', "Permission \"$name\" already exists.");
        }
        $this->db->transaction(function () use ($actorId, $name, $description): void {
            $this->rbac->addPermission((new Permission($name))->withDescription($description));
            if ($this->items->getRole(RbacCatalog::SUPERADMIN) !== null) {
                $this->rbac->addChild(RbacCatalog::SUPERADMIN, $name); // superadmin = every permission
            }
            $this->audit->record('rbac.permission.created', 'rbac_permission', $name, null, ['description' => $description], [],
                category: 'access', severity: 'notice', actorId: $actorId);
        });
        $this->access->forget();
    }

    public function deletePermission(string $actorId, string $name): void
    {
        $this->access->assert($actorId, 'rbac.manage');
        $perm = $this->items->getPermission($name) ?? throw new NotFoundException('Permission not found.');
        if ($this->catalog->isSystemPermission($name)) {
            throw new ConflictException('System permissions (declared in the catalog and checked by code) cannot be deleted.', 'system_permission');
        }
        $roles = array_keys($this->items->getParents($name));
        if (!$this->access->isSuperadmin($actorId) && !$this->access->can($actorId, $name)) {
            throw new AccessDeniedException('You can delete only permissions you hold.');
        }
        $this->db->transaction(function () use ($actorId, $name, $perm, $roles): void {
            $this->rbac->removePermission($name);
            $this->db->createCommand()->delete('rbac_link_overrides', ['child' => $name])->execute();
            $this->audit->record('rbac.permission.deleted', 'rbac_permission', $name, ['description' => $perm->getDescription(), 'roles' => $roles], null, [],
                category: 'access', severity: 'warning', actorId: $actorId);
        });
        $this->access->forget();
    }

    /**
     * Non-superadmins may grant only rights they hold themselves and never privileged permissions or admin roles.
     *
     * @param list<string> $permissions
     * @param list<string> $children
     */
    private function assertMayGrant(string $actorId, bool $super, array $permissions, array $children): void
    {
        if ($super) {
            return;
        }
        foreach ($children as $c) {
            if ($this->access->isAdminRole($c)) {
                throw new AccessDeniedException("Only a superadmin can inherit the administrative role \"$c\".");
            }
            $permissions = [...$permissions, ...array_keys($this->rbac->getPermissionsByRoleName($c))];
        }
        foreach (array_unique($permissions) as $p) {
            if (in_array($p, RbacCatalog::PRIVILEGED_PERMISSIONS, true)) {
                throw new AccessDeniedException("Only a superadmin can grant the privileged permission \"$p\".");
            }
            if (!$this->access->can($actorId, $p)) {
                throw new AccessDeniedException("You cannot grant \"$p\": you do not hold this permission yourself.");
            }
        }
    }

    /** @param list<string> $add @param list<string> $remove */
    private function recordOverrides(string $actorId, string $role, array $add, array $remove): void
    {
        $def = $this->catalog->roles()[$role];
        $declared = in_array('*', $def['permissions'], true) ? array_keys($this->catalog->permissions()) : $def['permissions'];
        $declared = [...$declared, ...$def['children']];
        foreach ([...array_map(static fn($c) => [$c, 'add'], $add), ...array_map(static fn($c) => [$c, 'remove'], $remove)] as [$child, $op]) {
            $isDeclared = in_array($child, $declared, true);
            if (($op === 'add' && $isDeclared) || ($op === 'remove' && !$isDeclared)) {
                // back to the catalog state → no override needed
                $this->db->createCommand()->delete('rbac_link_overrides', ['parent' => $role, 'child' => $child])->execute();
                continue;
            }
            $this->db->createCommand()->upsert('rbac_link_overrides',
                ['parent' => $role, 'child' => $child, 'op' => $op, 'created_by' => $actorId],
                ['op' => $op, 'created_by' => $actorId, 'created_at' => new Expression('now()')])->execute();
        }
    }

    /** @return array{0:list<string>,1:list<string>} direct permissions, direct child roles */
    private function directLinks(string $role): array
    {
        $perms = $children = [];
        foreach ($this->items->getDirectChildren($role) as $name => $item) {
            if ($item instanceof Role) {
                $children[] = $name;
            } else {
                $perms[] = $name;
            }
        }
        sort($perms);
        sort($children);
        return [$perms, $children];
    }

    /** @return array<string,bool> */
    private function flags(): array
    {
        $out = [];
        foreach ((new Query($this->db))->select(['name', 'is_admin'])->from('rbac_role_meta')->all() as $r) {
            $out[(string) $r['name']] = (bool) $r['is_admin'];
        }
        return $out;
    }
}
