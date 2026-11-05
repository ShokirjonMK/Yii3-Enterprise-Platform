<?php

declare(strict_types=1);

namespace App\Admin\Api;

use App\Admin\Users\AdminActor;
use App\Platform\Access\AccessService;
use App\Platform\Api\ApiClientService;
use App\Platform\Tenancy\TenantService;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;

/**
 * 06 · API Management — API clients (secret shown once, Argon2id hash stored), personal tokens of all users, usage.
 */
final class ApiController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AdminActor $actor,
        private readonly ApiClientService $clients,
        private readonly TenantService $tenants,
        private readonly AccessService $access,
        private readonly ConnectionInterface $db,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $filters = AdminActor::query($request, ['q', 'status', 'sort']);
        return $this->responder->render('@admin/Api/views/index', [
            'page' => $this->clients->list($actor, $filters, AdminActor::page($request), 25, $filters['sort'] ?: null),
            'filters' => $filters,
            'usage' => $this->clients->usage($actor),
            'cross' => $this->clients->tenantRestriction($actor) === null,
        ]);
    }

    public function tokens(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $filters = AdminActor::query($request, ['q', 'status', 'sort']);
        return $this->responder->render('@admin/Api/views/tokens', [
            'page' => $this->clients->personalTokens($actor, $filters, AdminActor::page($request), 25, $filters['sort'] ?: null),
            'filters' => $filters,
            'usage' => $this->clients->usage($actor),
        ]);
    }

    public function revokeToken(CurrentRoute $route): ResponseInterface
    {
        $this->clients->revokePersonalToken($this->actor->user(), (string) $route->getArgument('id'));
        $this->responder->success('Token revoked.');
        return $this->responder->redirect('admin.api.tokens');
    }

    public function createForm(): ResponseInterface
    {
        return $this->form(null, ['rate_limit_per_minute' => 600], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = AdminActor::body($request);
        try {
            $result = $this->clients->create($this->actor->user(), $input);
        } catch (ValidationException $e) {
            return $this->form(null, $input, $e->errors(), 422);
        }
        return $this->secretPage($result['client'], $result['secret'], 'created');
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $client = $this->clients->get($this->actor->user(), (string) $route->getArgument('id'));
        return $this->responder->render('@admin/Api/views/client', [
            'client' => $client,
            'history' => (new Query($this->db))
                ->select(['occurred_at', 'action', 'result', 'actor_name', 'actor_id'])
                ->from('audit_log')
                ->where(['entity_type' => 'api_client', 'entity_id' => $client['id']])
                ->orderBy(['occurred_at' => SORT_DESC])->limit(20)->all(),
        ]);
    }

    public function editForm(CurrentRoute $route): ResponseInterface
    {
        $client = $this->clients->get($this->actor->user(), (string) $route->getArgument('id'));
        $values = $client;
        $values['scopes'] = implode(' ', $client['scopes']);
        $values['allowed_ips'] = implode("\n", $client['allowed_ips']);
        $values['expires_at'] = $client['expires_at'] !== null ? (new \DateTimeImmutable((string) $client['expires_at']))->format('Y-m-d\TH:i') : '';
        return $this->form((string) $client['id'], $values, []);
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $client = $this->clients->get($actor, (string) $route->getArgument('id'));
        $input = AdminActor::body($request);
        try {
            $this->clients->update($actor, (string) $client['id'], $input);
        } catch (ValidationException $e) {
            return $this->form((string) $client['id'], $input + $client, $e->errors(), 422);
        }
        $this->responder->success('API client updated.');
        return $this->responder->redirect('admin.api.clients.view', ['id' => $client['id']]);
    }

    public function enable(CurrentRoute $route): ResponseInterface
    {
        return $this->toggle($route, true);
    }

    public function disable(CurrentRoute $route): ResponseInterface
    {
        return $this->toggle($route, false);
    }

    public function rotate(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $client = $this->clients->get($actor, (string) $route->getArgument('id'));
        $secret = $this->clients->rotateSecret($actor, (string) $client['id']);
        return $this->secretPage($client, $secret, 'rotated');
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $this->clients->delete($this->actor->user(), (string) $route->getArgument('id'));
        $this->responder->success('API client deleted.');
        return $this->responder->redirect('admin.api');
    }

    private function toggle(CurrentRoute $route, bool $enabled): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $this->clients->setEnabled($this->actor->user(), $id, $enabled);
        $this->responder->success($enabled ? 'API client enabled.' : 'API client disabled.');
        return $this->responder->redirect('admin.api.clients.view', ['id' => $id]);
    }

    private function secretPage(array $client, string $secret, string $reason): ResponseInterface
    {
        return $this->responder->render('@admin/Api/views/secret', ['client' => $client, 'secret' => $secret, 'reason' => $reason],
            status: $reason === 'created' ? 201 : 200)
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }

    private function form(?string $id, array $values, array $errors, int $status = 200): ResponseInterface
    {
        $actor = $this->actor->user();
        $cross = $this->clients->tenantRestriction($actor) === null;
        return $this->responder->render('@admin/Api/views/form', [
            'id' => $id,
            'values' => $values,
            'errors' => $errors,
            'cross' => $cross,
            'tenants' => $cross ? $this->tenants->options($actor) : [],
        ], status: $status);
    }
}
