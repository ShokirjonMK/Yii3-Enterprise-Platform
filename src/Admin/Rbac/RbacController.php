<?php

declare(strict_types=1);

namespace App\Admin\Rbac;

use App\Admin\Users\AdminActor;
use App\Platform\Access\AccessService;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RbacSynchronizer;
use App\Platform\Access\RoleManager;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Application\UserService;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Paginator;
use App\Platform\Tenancy\TextSearch;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * 04 · RBAC & Access Control — dynamic roles and permissions (create / edit / clone / delete via RoleManager),
 * effective permissions, permission matrix, users per role, role assignment/revocation via AccessService::setRoles
 * (guard rails apply), catalog synchronization (admin-panel overrides of system roles are preserved).
 *
 * Author: ShokirjonMK
 */
final class RbacController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AdminActor $actor,
        private readonly RbacCatalog $catalog,
        private readonly RbacSynchronizer $synchronizer,
        private readonly AccessService $access,
        private readonly UserService $users,
        private readonly UserRepository $repo,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
        private readonly RoleManager $roles,
        private readonly ItemsStorageInterface $items,
    ) {}

    public function index(): ResponseInterface
    {
        $actor = $this->actor->user();
        $counts = [];
        $q = (new Query($this->db))->select(['a.item_name', 'n' => new Expression('count(*)')])
            ->from(['a' => 'yii_rbac_assignment'])->innerJoin(['u' => 'users'], 'u.id::text = a.user_id')
            ->where(['u.deleted_at' => null])->groupBy('a.item_name');
        $restriction = $this->users->tenantRestriction($actor);
        if ($restriction !== null) {
            $q->andWhere(['u.tenant_id' => $restriction]);
        }
        foreach ($q->all() as $r) {
            $counts[$r['item_name']] = (int) $r['n'];
        }
        $roles = [];
        foreach ($this->roles->roles() as $name => $def) {
            $roles[$name] = $def + ['users' => $counts[$name] ?? 0];
        }
        return $this->responder->render('@admin/Rbac/views/index', [
            'roles' => $roles,
            'permissionCount' => count($this->items->getPermissions()),
            'drift' => $this->drift(),
            'isSuper' => $this->access->isSuperadmin($actor->id),
        ]);
    }

    public function matrix(): ResponseInterface
    {
        $roles = array_keys($this->roles->roles());
        $grid = [];
        foreach ($roles as $role) {
            $grid[$role] = array_flip($this->roles->effective($role));
        }
        $groups = [];
        foreach ($this->roles->permissions() as $perm => $p) {
            $groups[strstr($perm, '.', true) ?: $perm][$perm] = $p['description'];
        }
        return $this->responder->render('@admin/Rbac/views/matrix', ['roles' => $roles, 'groups' => $groups, 'grid' => $grid]);
    }

    public function role(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $name = (string) $route->getArgument('role');
        $role = $this->roles->role($name);
        $filters = AdminActor::query($request, ['q', 'sort']);
        $q = (new Query($this->db))
            ->select(['u.id', 'u.username', 'u.full_name', 'u.status', 't.name AS tenant_name', 'a.created_at AS assigned_at'])
            ->from(['a' => 'yii_rbac_assignment'])
            ->innerJoin(['u' => 'users'], 'u.id::text = a.user_id')
            ->leftJoin(['t' => 'tenants'], 't.id = u.tenant_id')
            ->where(['a.item_name' => $name, 'u.deleted_at' => null]);
        $restriction = $this->users->tenantRestriction($actor);
        if ($restriction !== null) {
            $q->andWhere(['u.tenant_id' => $restriction]);
        }
        if ($filters['q'] !== '') {
            $q->andWhere(TextSearch::condition($filters['q'], ['u.username', 'u.full_name']));
        }
        $page = Paginator::paginate(
            $q,
            AdminActor::page($request),
            25,
            $filters['sort'] ?: null,
            ['username' => 'u.username', 'full_name' => 'u.full_name', 'status' => 'u.status', 'assigned_at' => 'a.created_at'],
            'username',
        );

        $effective = $this->roles->effective($name);
        $grouped = [];
        foreach ($effective as $p) {
            $grouped[strstr($p, '.', true) ?: $p][] = $p;
        }
        $permissions = $this->roles->permissions();
        $allGroups = [];
        foreach ($permissions as $p => $def) {
            $allGroups[strstr($p, '.', true) ?: $p][$p] = $def;
        }
        $isAdminRole = $role['admin'];
        $isSuper = $this->access->isSuperadmin($actor->id);
        $manage = $this->access->can($actor->id, 'rbac.manage');
        return $this->responder->render('@admin/Rbac/views/role', [
            'name' => $name,
            'role' => $role,
            'direct' => $name === RbacCatalog::SUPERADMIN ? ['*'] : $role['permissions'],
            'grouped' => $grouped,
            'descriptions' => array_map(static fn(array $p) => $p['description'], $permissions),
            'allGroups' => $allGroups,
            'inherited' => array_flip(array_diff($effective, $role['permissions'])),
            'allRoles' => array_values(array_diff(array_keys($this->roles->roles()), [$name])),
            'overrides' => $role['system'] ? $this->roles->overridesOf($name) : [],
            'page' => $page,
            'filters' => $filters,
            'isAdminRole' => $isAdminRole,
            'isSuper' => $isSuper,
            'mayAssign' => $manage && (!$isAdminRole || $isSuper),
            'mayEdit' => $manage && $name !== RbacCatalog::SUPERADMIN && (!$isAdminRole || $isSuper),
            'mayDelete' => $manage && !$role['system'] && (!$isAdminRole || $isSuper),
        ]);
    }

    public function permissions(): ResponseInterface
    {
        $actor = $this->actor->user();
        $groups = [];
        foreach ($this->roles->permissions() as $p => $def) {
            $groups[strstr($p, '.', true) ?: $p][$p] = $def;
        }
        return $this->responder->render('@admin/Rbac/views/permissions', [
            'groups' => $groups,
            'manage' => $this->access->can($actor->id, 'rbac.manage'),
        ]);
    }

    public function createRole(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $b = AdminActor::body($request);
        $name = trim((string) ($b['name'] ?? ''));
        try {
            $this->roles->createRole(
                $actor->id,
                $name,
                (string) ($b['description'] ?? ''),
                !empty($b['is_admin']),
                trim((string) ($b['clone_from'] ?? '')) ?: null,
            );
        } catch (ValidationException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.rbac');
        }
        $this->responder->success(sprintf('Role "%s" created.', $name));
        return $this->responder->redirect('admin.rbac.role', ['role' => $name]);
    }

    public function updateRole(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $name = $this->roleArg($route);
        $b = AdminActor::body($request);
        $perms = array_map('strval', (array) ($b['permissions'] ?? []));
        // "Whole group" checkboxes grant every permission with that prefix (server-side, no JavaScript needed)
        $wantedGroups = array_map('strval', (array) ($b['groups'] ?? []));
        if ($wantedGroups !== []) {
            foreach (array_keys($this->roles->permissions()) as $p) {
                if (in_array(strstr($p, '.', true) ?: $p, $wantedGroups, true)) {
                    $perms[] = $p;
                }
            }
        }
        $isAdmin = array_key_exists('is_admin_present', $b) ? !empty($b['is_admin']) : null;
        try {
            $this->roles->updateRole(
                $actor->id,
                $name,
                $perms,
                array_map('strval', (array) ($b['children'] ?? [])),
                array_key_exists('description', $b) ? (string) $b['description'] : null,
                $isAdmin,
            );
        } catch (ValidationException|ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.rbac.role', ['role' => $name]);
        }
        $this->responder->success(sprintf('Role "%s" updated.', $name));
        return $this->responder->redirect('admin.rbac.role', ['role' => $name]);
    }

    public function deleteRole(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $name = $this->roleArg($route);
        try {
            $n = $this->roles->deleteRole($actor->id, $name);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.rbac.role', ['role' => $name]);
        }
        $this->responder->success(sprintf('Role "%s" deleted (%d assignment(s) removed).', $name, $n));
        return $this->responder->redirect('admin.rbac');
    }

    public function resetRole(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $name = $this->roleArg($route);
        try {
            $n = $this->roles->resetRole($actor->id, $name, $this->synchronizer);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.rbac.role', ['role' => $name]);
        }
        $this->responder->success(sprintf('Role "%s" reset to catalog defaults (%d override(s) removed).', $name, $n));
        return $this->responder->redirect('admin.rbac.role', ['role' => $name]);
    }

    public function createPermission(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $b = AdminActor::body($request);
        $name = trim((string) ($b['name'] ?? ''));
        try {
            $this->roles->createPermission($actor->id, $name, (string) ($b['description'] ?? ''));
        } catch (ValidationException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.rbac.permissions');
        }
        $this->responder->success(sprintf('Permission "%s" created.', $name));
        return $this->responder->redirect('admin.rbac.permissions');
    }

    public function deletePermission(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $name = (string) $route->getArgument('perm');
        try {
            $this->roles->deletePermission($actor->id, $name);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.rbac.permissions');
        }
        $this->responder->success(sprintf('Permission "%s" deleted.', $name));
        return $this->responder->redirect('admin.rbac.permissions');
    }

    public function assign(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $role = $this->roleArg($route);
        $login = trim((string) (AdminActor::body($request)['username'] ?? ''));
        $target = $login !== '' ? $this->repo->findByLogin($login) : null;
        try {
            if ($target === null) {
                throw new NotFoundException('User not found.');
            }
            $target = $this->users->get($actor, $target->id); // tenant isolation
            $this->access->setRoles($actor->id, $target->id, array_values(array_unique([...$this->access->directRoles($target->id), $role])));
        } catch (NotFoundException) {
            $this->responder->error('User not found.');
            return $this->responder->redirect('admin.rbac.role', ['role' => $role]);
        } catch (ValidationException|ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.rbac.role', ['role' => $role]);
        }
        $this->responder->success(sprintf('Role "%s" assigned to %s.', $role, $target->username));
        return $this->responder->redirect('admin.rbac.role', ['role' => $role]);
    }

    public function revoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $role = $this->roleArg($route);
        $userId = (string) (AdminActor::body($request)['user_id'] ?? '');
        $target = $this->users->get($actor, $userId); // tenant isolation (NotFound for foreign users)
        try {
            $this->access->setRoles($actor->id, $target->id, array_values(array_diff($this->access->directRoles($target->id), [$role])));
        } catch (ValidationException|ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.rbac.role', ['role' => $role]);
        }
        $this->responder->success(sprintf('Role "%s" revoked from %s.', $role, $target->username));
        return $this->responder->redirect('admin.rbac.role', ['role' => $role]);
    }

    public function sync(): ResponseInterface
    {
        $actor = $this->actor->user();
        $this->access->assert($actor->id, 'rbac.manage');
        $stats = $this->db->transaction(function () use ($actor): array {
            $stats = $this->synchronizer->sync();
            $this->audit->record('rbac.catalog.synced', 'rbac', null, null, null, $stats, category: 'access', severity: 'notice', actorId: $actor->id);
            return $stats;
        });
        $this->access->forget();
        $this->responder->success(sprintf(
            'Catalog synchronized: %d permission(s) and %d role(s) created, %d link(s) added, %d removed.',
            $stats['permissions_created'],
            $stats['roles_created'],
            $stats['links_added'],
            $stats['links_removed'],
        ));
        return $this->responder->redirect('admin.rbac');
    }

    private function roleArg(CurrentRoute $route): string
    {
        $role = (string) $route->getArgument('role');
        if ($this->items->getRole($role) === null) {
            throw new NotFoundException('Role not found.');
        }
        return $role;
    }

    /**
     * Differences between the catalog and the RBAC storage (shown as "sync needed").
     *
     * @return array{missing_permissions:int, missing_roles:int}
     */
    private function drift(): array
    {
        $stored = (new Query($this->db))->select(['name', 'type'])->from('yii_rbac_item')->all();
        $perms = $roles = [];
        foreach ($stored as $r) {
            if ($r['type'] === 'permission') {
                $perms[$r['name']] = true;
            } else {
                $roles[$r['name']] = true;
            }
        }
        return [
            'missing_permissions' => count(array_diff_key($this->catalog->permissions(), $perms)),
            'missing_roles' => count(array_diff_key($this->catalog->roles(), $roles)),
        ];
    }
}
