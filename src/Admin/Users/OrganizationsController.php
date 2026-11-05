<?php

declare(strict_types=1);

namespace App\Admin\Users;

use App\Admin\Users\AdminActor;
use App\Platform\Organizations\OrganizationService;
use App\Platform\Tenancy\TenantService;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * 02 · Organizations — per-tenant tree with create/edit/move/delete.
 */
final class OrganizationsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AdminActor $actor,
        private readonly OrganizationService $organizations,
        private readonly TenantService $tenants,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $filters = AdminActor::query($request, ['tenant_id', 'q', 'status']);
        $tenantId = $this->organizations->resolveTenant($actor, $filters['tenant_id'] ?: null);
        return $this->responder->render('@admin/Users/views/organizations/index', [
            'tree' => $this->organizations->tree($actor, $tenantId, $filters['q'], $filters['status']),
            'filters' => ['tenant_id' => $tenantId ?? ''] + $filters,
            'cross' => $this->organizations->tenantRestriction($actor) === null,
            'tenants' => $this->tenants->options($actor),
            'statuses' => OrganizationService::STATUSES,
        ]);
    }

    public function createForm(ServerRequestInterface $request): ResponseInterface
    {
        $values = AdminActor::query($request, ['tenant_id', 'parent_id']);
        return $this->form(null, $values + ['type' => 'department', 'status' => 'active'], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = AdminActor::body($request);
        try {
            $org = $this->organizations->create($this->actor->user(), $input);
        } catch (ValidationException $e) {
            return $this->form(null, $input, $e->errors(), 422);
        }
        $this->responder->success('Organization created.');
        return $this->responder->redirect('admin.organizations', [], ['tenant_id' => $org['tenant_id']]);
    }

    public function editForm(CurrentRoute $route): ResponseInterface
    {
        $org = $this->organizations->get($this->actor->user(), (string) $route->getArgument('id'));
        return $this->form((string) $org['id'], $org, []);
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $org = $this->organizations->get($actor, (string) $route->getArgument('id'));
        $input = array_intersect_key(AdminActor::body($request), array_flip(['code', 'name', 'inn', 'type', 'status', 'parent_id']));
        try {
            $this->organizations->update($actor, (string) $org['id'], $input);
        } catch (ValidationException $e) {
            return $this->form((string) $org['id'], $input + $org, $e->errors(), 422);
        }
        $this->responder->success('Organization updated.');
        return $this->responder->redirect('admin.organizations', [], ['tenant_id' => $org['tenant_id']]);
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $org = $this->organizations->get($actor, (string) $route->getArgument('id'));
        try {
            $this->organizations->delete($actor, (string) $org['id']);
            $this->responder->success('Organization deleted.');
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.organizations', [], ['tenant_id' => $org['tenant_id']]);
    }

    private function form(?string $id, array $values, array $errors, int $status = 200): ResponseInterface
    {
        $actor = $this->actor->user();
        $cross = $this->organizations->tenantRestriction($actor) === null;
        $tenantId = $id !== null ? (string) $values['tenant_id'] : $this->organizations->resolveTenant($actor, ($values['tenant_id'] ?? '') ?: null);
        return $this->responder->render('@admin/Users/views/organizations/form', [
            'id' => $id,
            'values' => ['tenant_id' => $tenantId] + $values,
            'errors' => $errors,
            'cross' => $cross,
            'tenants' => $this->tenants->options($actor),
            'parents' => $tenantId !== null ? $this->organizations->options($actor, $tenantId, $id) : [],
            'types' => OrganizationService::TYPES,
            'statuses' => OrganizationService::STATUSES,
        ], status: $status);
    }
}
