<?php

declare(strict_types=1);

namespace App\Admin\Tenants;

use App\Admin\Users\AdminActor;
use App\Platform\Access\AccessService;
use App\Platform\Tenancy\TenantService;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;

/**
 * 05 · Multi-Tenancy — tenants CRUD, status (suspend revokes sessions), per-tenant feature-flag overrides.
 */
final class TenantsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AdminActor $actor,
        private readonly TenantService $tenants,
        private readonly AccessService $access,
        private readonly ConnectionInterface $db,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $filters = AdminActor::query($request, ['q', 'status', 'sort']);
        return $this->responder->render('@admin/Tenants/views/index', [
            'page' => $this->tenants->list($actor, $filters, AdminActor::page($request), 25, $filters['sort'] ?: null),
            'filters' => $filters,
            'counts' => $this->tenants->counts($actor),
            'statuses' => TenantService::STATUSES,
            'mayCreate' => $this->access->can($actor->id, 'tenants.manage') && $this->access->can($actor->id, 'tenants.cross'),
        ]);
    }

    public function createForm(): ResponseInterface
    {
        return $this->responder->render('@admin/Tenants/views/form', ['id' => null, 'values' => [], 'errors' => []]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = AdminActor::body($request);
        try {
            $tenant = $this->tenants->create($this->actor->user(), $input);
        } catch (ValidationException $e) {
            return $this->responder->render('@admin/Tenants/views/form', ['id' => null, 'values' => $input, 'errors' => $e->errors()], status: 422);
        }
        $this->responder->success('Tenant created.');
        return $this->responder->redirect('admin.tenants.view', ['id' => $tenant['id']]);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $tenant = $this->tenants->get($actor, (string) $route->getArgument('id'));
        return $this->responder->render('@admin/Tenants/views/view', [
            'tenant' => $tenant,
            'flags' => $this->tenants->flagOverrides($actor, (string) $tenant['id']),
            'sessions' => (int) $this->db->createCommand(
                'SELECT count(*) FROM user_sessions s JOIN users u ON u.id = s.user_id
                 WHERE u.tenant_id = :t AND s.revoked_at IS NULL AND s.expires_at > now()',
                [':t' => $tenant['id']],
            )->queryScalar(),
            'history' => (new Query($this->db))
                ->select(['occurred_at', 'action', 'result', 'actor_name', 'actor_id', 'metadata'])
                ->from('audit_log')
                ->where(['entity_type' => 'tenant', 'entity_id' => $tenant['id']])
                ->orderBy(['occurred_at' => SORT_DESC])->limit(20)->all(),
            'mayCross' => $this->access->can($actor->id, 'tenants.cross'),
            'mayFlags' => $this->access->can($actor->id, 'settings.manage'),
        ]);
    }

    public function editForm(CurrentRoute $route): ResponseInterface
    {
        $tenant = $this->tenants->get($this->actor->user(), (string) $route->getArgument('id'));
        return $this->responder->render('@admin/Tenants/views/form', ['id' => $tenant['id'], 'values' => $tenant, 'errors' => []]);
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $tenant = $this->tenants->get($actor, (string) $route->getArgument('id'));
        $input = array_intersect_key(AdminActor::body($request), array_flip(['name', 'domain']));
        try {
            $this->tenants->update($actor, (string) $tenant['id'], $input);
        } catch (ValidationException $e) {
            return $this->responder->render('@admin/Tenants/views/form', ['id' => $tenant['id'], 'values' => $input + $tenant, 'errors' => $e->errors()], status: 422);
        }
        $this->responder->success('Tenant updated.');
        return $this->responder->redirect('admin.tenants.view', ['id' => $tenant['id']]);
    }

    public function status(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $id = (string) $route->getArgument('id');
        $status = (string) (AdminActor::body($request)['status'] ?? '');
        try {
            $result = $this->tenants->setStatus($actor, $id, $status);
        } catch (ConflictException|ValidationException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.tenants.view', ['id' => $id]);
        }
        $this->responder->success($status === 'active'
            ? 'Tenant activated.'
            : sprintf('Tenant %s. %d session(s) and %d API token(s) revoked.', $status, $result['sessions_revoked'], $result['tokens_revoked']));
        return $this->responder->redirect('admin.tenants.view', ['id' => $id]);
    }

    public function flag(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $id = (string) $route->getArgument('id');
        $body = AdminActor::body($request);
        $value = match ((string) ($body['value'] ?? '')) {
            'on' => true,
            'off' => false,
            'inherit' => null,
            default => throw ValidationException::single('value', 'Choose on, off or inherit.'),
        };
        try {
            $this->tenants->setFlagOverride($actor, $id, (string) ($body['code'] ?? ''), $value);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.tenants.view', ['id' => $id]);
        }
        $this->responder->success('Feature flag override saved.');
        return $this->responder->redirect('admin.tenants.view', ['id' => $id]);
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $id = (string) $route->getArgument('id');
        try {
            $this->tenants->delete($actor, $id);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.tenants.view', ['id' => $id]);
        }
        $this->responder->success('Tenant deleted.');
        return $this->responder->redirect('admin.tenants');
    }
}
