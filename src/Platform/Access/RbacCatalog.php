<?php

declare(strict_types=1);

namespace App\Platform\Access;

use RuntimeException;

/**
 * Loads and merges all RBAC catalog files from config/common/permissions/*.php.
 */
final class RbacCatalog
{
    public const SUPERADMIN = 'superadmin';

    /** Roles that only a superadmin may grant or revoke. */
    public const ADMIN_ROLES = ['superadmin', 'platform_admin', 'security_admin', 'devops_sre'];

    /**
     * Permissions that make any role holding them (directly or by inheritance) administrative: they allow changing
     * who may do what (rbac.*, auth.manage, security.manage, settings.manage), crossing tenant boundaries,
     * impersonation, or destructive operations on data stores/infrastructure/backups and production deployments.
     */
    public const PRIVILEGED_PERMISSIONS = [
        'rbac.manage', 'rbac.grant_admin', 'auth.manage', 'security.manage', 'settings.manage', 'tenants.cross',
        'users.impersonate', 'db.manage', 'infra.manage', 'backup.manage', 'deploy.approve',
    ];

    /** Roles and permissions declared in the catalog files are "system" items (cannot be deleted in the panel). */
    public function isSystemRole(string $name): bool
    {
        return isset($this->roles()[$name]);
    }

    public function isSystemPermission(string $name): bool
    {
        return isset($this->permissions()[$name]);
    }

    private ?array $merged = null;

    /** @param list<string> $moduleFiles src/Modules/<Name>/config/permissions.php */
    public function __construct(private readonly string $directory, private readonly array $moduleFiles = []) {}

    /** @return array<string, string> */
    public function permissions(): array
    {
        return $this->load()['permissions'];
    }

    /** @return array<string, array{description:string, permissions:list<string>, children:list<string>}> */
    public function roles(): array
    {
        return $this->load()['roles'];
    }

    /**
     * Effective permission set of a role including inherited roles.
     *
     * @return list<string>
     */
    public function effectivePermissions(string $role, array $seen = []): array
    {
        $roles = $this->roles();
        if (!isset($roles[$role]) || in_array($role, $seen, true)) {
            return [];
        }
        $perms = $roles[$role]['permissions'];
        if (in_array('*', $perms, true)) {
            return array_keys($this->permissions());
        }
        foreach ($roles[$role]['children'] as $child) {
            $perms = array_merge($perms, $this->effectivePermissions($child, [...$seen, $role]));
        }
        $perms = array_values(array_unique($perms));
        sort($perms);
        return $perms;
    }

    private function load(): array
    {
        if ($this->merged !== null) {
            return $this->merged;
        }
        $permissions = [];
        $roles = [];
        $files = glob(rtrim($this->directory, '/') . '/*.php') ?: [];
        sort($files);
        $files = [...$files, ...$this->moduleFiles];
        foreach ($files as $file) {
            /** @var array{permissions?:array<string,string>, roles?:array<string,array>} $data */
            $data = require $file;
            foreach ($data['permissions'] ?? [] as $name => $description) {
                if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $name)) {
                    throw new RuntimeException("Invalid permission name \"$name\" in $file");
                }
                $permissions[$name] = $description;
            }
            foreach ($data['roles'] ?? [] as $role => $def) {
                $roles[$role] ??= ['description' => '', 'permissions' => [], 'children' => []];
                $roles[$role]['description'] = $def['description'] ?? $roles[$role]['description'];
                $roles[$role]['permissions'] = array_values(array_unique([...$roles[$role]['permissions'], ...($def['permissions'] ?? [])]));
                $roles[$role]['children'] = array_values(array_unique([...$roles[$role]['children'], ...($def['children'] ?? [])]));
            }
        }
        foreach ($roles as $role => $def) {
            foreach ($def['permissions'] as $p) {
                if ($p !== '*' && !isset($permissions[$p])) {
                    throw new RuntimeException("Role \"$role\" references unknown permission \"$p\"");
                }
            }
            foreach ($def['children'] as $c) {
                if (!isset($roles[$c])) {
                    throw new RuntimeException("Role \"$role\" references unknown child role \"$c\"");
                }
            }
        }
        return $this->merged = ['permissions' => $permissions, 'roles' => $roles];
    }
}
