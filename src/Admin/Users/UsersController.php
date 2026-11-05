<?php

declare(strict_types=1);

namespace App\Admin\Users;

use App\Platform\Access\AccessService;
use App\Platform\Access\RbacCatalog;
use App\Platform\Identity\Application\UserService;
use App\Platform\Identity\Domain\AuthPolicy;
use App\Platform\Identity\Domain\UserStatus;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Identity\Sso\SsoService;
use App\Platform\Organizations\OrganizationService;
use App\Platform\Tenancy\TenantService;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use App\Web\Auth\WebAuthSession;
use App\Platform\Identity\Application\SessionRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * 02 · Users — list/filter, create, edit, block/unblock, archive, password reset (temporary password shown once),
 * unlock, role assignment, active sessions, linked SSO identities.
 */
final class UsersController
{
    private const FILTERS = ['q', 'status', 'tenant_id', 'organization_id', 'role', 'sort'];

    public function __construct(
        private readonly Responder $responder,
        private readonly AdminActor $actor,
        private readonly UserService $users,
        private readonly UserRepository $repo,
        private readonly AccessService $access,
        private readonly RbacCatalog $catalog,
        private readonly \App\Platform\Access\RoleManager $roleManager,
        private readonly \App\Platform\Identity\Mfa\MfaManager $mfaManager,
        private readonly \App\Platform\Audit\AuditLogger $audit,
        private readonly OrganizationService $organizations,
        private readonly TenantService $tenants,
        private readonly SsoService $sso,
        private readonly WebAuthSession $webAuth,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $filters = AdminActor::query($request, self::FILTERS);
        $page = $this->users->list($actor, $filters, AdminActor::page($request), 25, $filters['sort'] ?: null);
        $cross = $this->users->tenantRestriction($actor) === null;
        return $this->responder->render('@admin/Users/views/index', [
            'page' => $page,
            'filters' => $filters,
            'cross' => $cross,
            'tenants' => $cross ? $this->tenants->options($actor) : [],
            'organizations' => $this->organizations->options($actor, $cross ? ($filters['tenant_id'] ?: null) : null),
            'roles' => array_keys($this->catalog->roles()),
            'statuses' => UserStatus::ALL,
        ]);
    }

    public function createForm(ServerRequestInterface $request): ResponseInterface
    {
        $values = AdminActor::query($request, ['username', 'full_name', 'email', 'phone', 'auth_policy', 'tenant_id', 'organization_id', 'locale', 'mfa_required']);
        return $this->form(null, $values, []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $input = AdminActor::body($request);
        $input['mfa_required'] = !empty($input['mfa_required']);
        try {
            $tenantId = $this->users->tenantRestriction($actor) ?? (trim((string) ($input['tenant_id'] ?? '')) ?: null);
            if ($tenantId !== null && $this->users->tenantRestriction($actor) === null) {
                $this->tenants->load($actor, $tenantId); // must exist
            }
            $this->organizations->assertAssignable($actor, $tenantId, trim((string) ($input['organization_id'] ?? '')) ?: null);
            $result = $this->users->create($actor, $input);
        } catch (ValidationException $e) {
            return $this->form(null, $input, $e->errors(), 422);
        } catch (NotFoundException) {
            return $this->form(null, $input, ['tenant_id' => ['Unknown tenant.']], 422);
        } catch (ConflictException $e) {
            return $this->form(null, $input, ['username' => [$e->getMessage()]], 422);
        }
        $user = $result['user'];
        if ($result['temporaryPassword'] !== null) {
            return $this->secretPage($user->id, $user->username, $result['temporaryPassword'], 'created');
        }
        $this->responder->success('User created.');
        return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id'));
        return $this->responder->render('@admin/Users/views/view', [
            'user' => $user,
            'tenant' => $user->tenantId !== null ? $this->safeTenant($actor, $user->tenantId) : null,
            'organization' => $user->organizationId !== null ? $this->safeOrganization($actor, $user->organizationId) : null,
            'roles' => $this->access->rolesOf($user->id),
            'directRoles' => $this->access->directRoles($user->id),
            'catalogRoles' => $allRoles = $this->roleManager->roles(),
            'adminRoles' => array_keys(array_filter($allRoles, static fn(array $r) => $r['admin'])),
            'mfaMethods' => $this->mfaManager->enrolled($user),
            'mfaPolicy' => $this->mfaManager->policy($user),
            'actorIsSuper' => $this->access->isSuperadmin($actor->id),
            'isSelf' => $actor->id === $user->id,
            'sessions' => $this->users->activeSessions($actor, $user->id),
            'currentSession' => SessionRegistry::hash((string) $this->webAuth->sessionId()),
            'identities' => $this->users->linkedIdentities($actor, $user->id),
        ]);
    }

    public function editForm(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id'));
        return $this->form($user->id, $user->toPublicArray() + ['mfa_required' => $user->mfaRequired, 'version' => $user->version], []);
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id'));
        $input = AdminActor::body($request);
        $values = $input;
        $data = array_intersect_key($input, array_flip(['email', 'phone', 'full_name', 'auth_policy', 'organization_id', 'locale', 'tenant_id']));
        $data['mfa_required'] = !empty($input['mfa_required']);
        try {
            $this->users->assertMayAdminister($actor, $user);
            $restricted = $this->users->tenantRestriction($actor) !== null;
            $tenantId = $restricted ? $user->tenantId : (array_key_exists('tenant_id', $data) ? (trim((string) $data['tenant_id']) ?: null) : $user->tenantId);
            if (!$restricted && $tenantId !== null) {
                $this->tenants->load($actor, $tenantId);
            }
            $this->organizations->assertAssignable($actor, $tenantId, trim((string) ($data['organization_id'] ?? '')) ?: null);
            $version = isset($input['version']) && ctype_digit((string) $input['version']) ? (int) $input['version'] : null;
            $this->users->update($actor, $user->id, $data, $version);
        } catch (ValidationException $e) {
            return $this->form($user->id, $values, $e->errors(), 422);
        } catch (NotFoundException) {
            return $this->form($user->id, $values, ['tenant_id' => ['Unknown tenant.']], 422);
        } catch (ConflictException $e) {
            return $this->form($user->id, $values, ['_form' => [$e->getMessage()]], 409);
        }
        $this->responder->success('User updated.');
        return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
    }

    public function block(CurrentRoute $route): ResponseInterface
    {
        return $this->changeStatus($route, UserStatus::BLOCKED, 'User blocked. Sessions and API tokens were revoked.');
    }

    public function unblock(CurrentRoute $route): ResponseInterface
    {
        return $this->changeStatus($route, UserStatus::ACTIVE, 'User unblocked.');
    }

    public function archive(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id'));
        try {
            $this->users->assertMayAdminister($actor, $user);
            $this->users->archive($actor, $user->id);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
        }
        $this->responder->success('User archived.');
        return $this->responder->redirect('admin.users');
    }

    public function resetPassword(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id'));
        try {
            $this->users->assertMayAdminister($actor, $user);
            $temporary = $this->users->resetPassword($actor, $user->id);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
        }
        return $this->secretPage($user->id, $user->username, $temporary, 'reset');
    }

    public function unlock(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id'));
        $this->users->assertMayAdminister($actor, $user);
        $this->users->unlock($actor, $user->id);
        $this->responder->success('Account unlocked.');
        return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
    }

    /** Removes every second factor (lost phone / key): the user enrols again at next sign-in if the policy requires it. */
    public function resetMfa(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id'));
        $this->users->assertMayAdminister($actor, $user);
        if ($actor->id === $user->id) {
            $this->responder->error('Use Account → Security to change your own two-factor methods.');
            return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
        }
        $before = array_column($this->mfaManager->enrolled($user), 'type');
        $this->mfaManager->resetAll($user->id);
        $this->audit->record('user.mfa_reset', 'user', $user->id, ['methods' => $before], ['methods' => []], [], category: 'security', severity: 'warning', actorId: $actor->id);
        $this->responder->success('Two-factor authentication was reset. The user sets it up again at the next sign-in.');
        return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
    }

    public function roles(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id')); // tenant isolation
        $roles = AdminActor::body($request)['roles'] ?? [];
        $roles = array_values(array_filter(is_array($roles) ? $roles : [], 'is_string'));
        try {
            $this->access->setRoles($actor->id, $user->id, $roles);
        } catch (ValidationException|ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
        }
        $this->responder->success('Roles updated.');
        return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
    }

    public function revokeSession(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $id = (string) $route->getArgument('id');
        $this->users->revokeSession($actor, $id, (string) $route->getArgument('sid'));
        $this->responder->success('Session terminated.');
        return $this->responder->redirect('admin.users.view', ['id' => $id]);
    }

    public function revokeAllSessions(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $id = (string) $route->getArgument('id');
        $n = $this->users->revokeAllSessions($actor, $id);
        $this->responder->success(sprintf('%d session(s) terminated.', $n));
        return $this->responder->redirect('admin.users.view', ['id' => $id]);
    }

    public function unlinkIdentity(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id'));
        $this->users->assertMayAdminister($actor, $user);
        $identity = $this->repo->findIdentity($user->id, (string) $route->getArgument('iid')) ?? throw new NotFoundException('Link not found.');
        $this->sso->unlink($actor->id, (string) $identity['id']);
        $this->responder->success('External identity unlinked.');
        return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
    }

    private function changeStatus(CurrentRoute $route, string $status, string $message): ResponseInterface
    {
        $actor = $this->actor->user();
        $user = $this->users->get($actor, (string) $route->getArgument('id'));
        try {
            $this->users->assertMayAdminister($actor, $user);
            $this->users->setStatus($actor, $user->id, $status);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
        }
        $this->responder->success($message);
        return $this->responder->redirect('admin.users.view', ['id' => $user->id]);
    }

    private function secretPage(string $id, string $username, string $password, string $reason): ResponseInterface
    {
        return $this->responder->render('@admin/Users/views/temporary-password', [
            'id' => $id,
            'username' => $username,
            'password' => $password,
            'reason' => $reason,
        ], status: $reason === 'created' ? 201 : 200)
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }

    private function form(?string $id, array $values, array $errors, int $status = 200): ResponseInterface
    {
        $actor = $this->actor->user();
        $cross = $this->users->tenantRestriction($actor) === null;
        $tenantId = $cross ? (trim((string) ($values['tenant_id'] ?? '')) ?: null) : $actor->tenantId;
        unset($values['password']);
        return $this->responder->render('@admin/Users/views/form', [
            'id' => $id,
            'values' => $values,
            'errors' => $errors,
            'cross' => $cross,
            'tenants' => $cross ? $this->tenants->options($actor) : [],
            'organizations' => $tenantId !== null || !$cross ? $this->organizations->options($actor, $tenantId) : [],
            'policies' => AuthPolicy::ALL,
        ], status: $status);
    }

    private function safeTenant($actor, string $id): ?array
    {
        try {
            return $this->tenants->load($actor, $id);
        } catch (NotFoundException|AccessDeniedException) {
            return null;
        }
    }

    private function safeOrganization($actor, string $id): ?array
    {
        try {
            return $this->organizations->load($actor, $id);
        } catch (NotFoundException) {
            return null;
        }
    }
}
