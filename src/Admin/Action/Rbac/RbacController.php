<?php

declare(strict_types=1);

namespace App\Admin\Action\Rbac;

use App\Admin\Action\Users\AdminActor;
use App\Platform\Access\AccessService;
use App\Platform\Access\RbacCatalog;
use App\Platform\Access\RbacSynchronizer;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Application\UserService;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use App\Platform\Tenancy\TextSearch;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;

/**
 * 04 · RBAC & Access Control — roles (catalog, read-only definitions), effective permissions,
 * permission matrix, users per role, role assignment/revocation via AccessService::setRoles (guard rails apply),
 * catalog synchronization.
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
        foreach ($this->catalog->roles() as $name => $def) {
            $roles[$name] = $def + [
                'effective' => count($this->catalog->effectivePermissions($name)),
                'users' => $counts[$name] ?? 0,
                'admin' => in_array($name, RbacCatalog::ADMIN_ROLES, true),
            ];
        }
        return $this->responder->render('admin/rbac/index', [
            'roles' => $roles,
            'permissionCount' => count($this->catalog->permissions()),
            'drift' => $this->drift(),
        ]);
    }

    public function matrix(): ResponseInterface
    {
        $roles = array_keys($this->catalog->roles());
        $grid = [];
        foreach ($roles as $role) {
            $grid[$role] = array_flip($this->catalog->effectivePermissions($role));
        }
        $groups = [];
        foreach ($this->catalog->permissions() as $perm => $desc) {
            $groups[strstr($perm, '.', true) ?: $perm][$perm] = $desc;
        }
        return $this->responder->render('admin/rbac/matrix', ['roles' => $roles, 'groups' => $groups, 'grid' => $grid]);
    }

    public function role(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $name = (string) $route->getArgument('role');
        $roles = $this->catalog->roles();
        if (!isset($roles[$name])) {
            throw new NotFoundException('Role not found.');
        }
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
        $page = Paginator::paginate($q, AdminActor::page($request), 25, $filters['sort'] ?: null,
            ['username' => 'u.username', 'full_name' => 'u.full_name', 'status' => 'u.status', 'assigned_at' => 'a.created_at'], 'username');

        $effective = $this->catalog->effectivePermissions($name);
        $grouped = [];
        foreach ($effective as $p) {
            $grouped[strstr($p, '.', true) ?: $p][] = $p;
        }
        $isAdminRole = in_array($name, RbacCatalog::ADMIN_ROLES, true);
        return $this->responder->render('admin/rbac/role', [
            'name' => $name,
            'role' => $roles[$name],
            'direct' => in_array('*', $roles[$name]['permissions'], true) ? ['*'] : $roles[$name]['permissions'],
            'grouped' => $grouped,
            'descriptions' => $this->catalog->permissions(),
            'page' => $page,
            'filters' => $filters,
            'isAdminRole' => $isAdminRole,
            'mayAssign' => $this->access->can($actor->id, 'rbac.manage') && (!$isAdminRole || $this->access->isSuperadmin($actor->id)),
        ]);
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
        $this->responder->success(sprintf('Catalog synchronized: %d permission(s) and %d role(s) created, %d link(s) added, %d removed.',
            $stats['permissions_created'], $stats['roles_created'], $stats['links_added'], $stats['links_removed']));
        return $this->responder->redirect('admin.rbac');
    }

    private function roleArg(CurrentRoute $route): string
    {
        $role = (string) $route->getArgument('role');
        if (!isset($this->catalog->roles()[$role])) {
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
