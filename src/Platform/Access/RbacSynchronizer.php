<?php

declare(strict_types=1);

namespace App\Platform\Access;

use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Rbac\Permission;
use Yiisoft\Rbac\Role;

/**
 * Idempotently applies the RBAC catalog to yiisoft/rbac storage:
 * creates missing permissions/roles, updates descriptions, adds missing parent→child links and
 * removes links that are no longer declared for catalog roles. Never deletes assignments.
 */
final class RbacSynchronizer
{
    public function __construct(
        private readonly RbacCatalog $catalog,
        private readonly ManagerInterface $manager,
        private readonly ItemsStorageInterface $items,
    ) {}

    /**
     * @return array{permissions_created:int, roles_created:int, links_added:int, links_removed:int}
     */
    public function sync(): array
    {
        $stats = ['permissions_created' => 0, 'roles_created' => 0, 'links_added' => 0, 'links_removed' => 0];
        $permissions = $this->catalog->permissions();
        $roles = $this->catalog->roles();

        foreach ($permissions as $name => $description) {
            $existing = $this->items->getPermission($name);
            if ($existing === null) {
                $this->manager->addPermission((new Permission($name))->withDescription($description));
                $stats['permissions_created']++;
            } elseif ($existing->getDescription() !== $description) {
                $this->manager->updatePermission($name, $existing->withDescription($description));
            }
        }

        foreach ($roles as $name => $def) {
            $existing = $this->items->getRole($name);
            if ($existing === null) {
                $this->manager->addRole((new Role($name))->withDescription($def['description']));
                $stats['roles_created']++;
            } elseif ($existing->getDescription() !== $def['description']) {
                $this->manager->updateRole($name, $existing->withDescription($def['description']));
            }
        }

        foreach ($roles as $name => $def) {
            // superadmin's "*" is expanded to every permission
            $wanted = in_array('*', $def['permissions'], true) ? array_keys($permissions) : $def['permissions'];
            $wanted = array_merge($wanted, $def['children']);
            $current = array_keys($this->items->getDirectChildren($name));

            foreach (array_diff($wanted, $current) as $child) {
                if ($this->manager->canAddChild($name, $child)) {
                    $this->manager->addChild($name, $child);
                    $stats['links_added']++;
                }
            }
            foreach (array_diff($current, $wanted) as $child) {
                $this->manager->removeChild($name, $child);
                $stats['links_removed']++;
            }
        }

        return $stats;
    }
}
