<?php

declare(strict_types=1);

namespace App\Admin\Action\Integrations;

use App\Platform\Integrations\IntegrationService;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 07 · Integrations — external systems: CRUD, enable/disable, credentials (write-only, step-up auth),
 * connection test, circuit breaker state/reset and recent calls (redacted integration_logs).
 */
final class IntegrationsController
{
    private const SORT = ['code' => 'code', 'name' => 'name', 'status' => 'status', 'updated_at' => 'updated_at', 'created_at' => 'created_at'];
    private const LOG_SORT = ['created_at' => 'l.created_at', 'duration_ms' => 'l.duration_ms', 'status_code' => 'l.status_code'];

    public function __construct(
        private readonly Responder $responder,
        private readonly IntegrationService $integrations,
        private readonly CurrentUser $user,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $query = $this->integrations->query();
        if (($q['q'] ?? '') !== '') {
            $query->andWhere(Sql::search((string) $q['q'], ['code', 'name', 'base_url']));
        }
        if (in_array($q['status'] ?? '', IntegrationService::STATUSES, true)) {
            $query->andWhere(['status' => $q['status']]);
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 25, (string) ($q['sort'] ?? ''), self::SORT, 'code');
        $page = $page->map(fn(array $r) => $r + ['breaker' => $this->integrations->breakerState($r)]);
        return $this->responder->render('admin/integrations/index', [
            'page' => $page, 'query' => array_intersect_key($q, ['q' => 1, 'status' => 1, 'sort' => 1]),
        ]);
    }

    public function create(): ResponseInterface
    {
        return $this->responder->render('admin/integrations/form', ['row' => null, 'values' => [], 'errors' => []]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = (array) $request->getParsedBody();
        try {
            $id = $this->integrations->create($this->actor(), $input);
        } catch (ValidationException $e) {
            return $this->responder->render('admin/integrations/form', ['row' => null, 'values' => $input, 'errors' => $e->errors()], status: 422);
        }
        $this->responder->success('Integration created. Set the credential and test the connection before enabling it.');
        return $this->responder->redirect('admin.integrations.show', ['id' => $id]);
    }

    public function show(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->integrations->find((string) $route->getArgument('id'));
        $q = $request->getQueryParams();
        $logs = Paginator::paginate($this->integrations->logsQuery((string) $row['id']), (int) ($q['page'] ?? 1), 25, (string) ($q['sort'] ?? ''), self::LOG_SORT, '-created_at');
        return $this->responder->render('admin/integrations/show', [
            'row' => $row,
            'breaker' => $this->integrations->breakerState($row),
            'hasSecret' => $this->integrations->hasSecret($row),
            'logs' => $logs,
            'query' => array_intersect_key($q, ['sort' => 1]),
        ]);
    }

    public function edit(CurrentRoute $route): ResponseInterface
    {
        $row = $this->integrations->find((string) $route->getArgument('id'));
        return $this->responder->render('admin/integrations/form', ['row' => $row, 'values' => self::formValues($row), 'errors' => []]);
    }

    public function update(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->integrations->find((string) $route->getArgument('id'));
        $input = (array) $request->getParsedBody();
        try {
            $this->integrations->update($this->actor(), (string) $row['id'], $input, isset($input['version']) ? (int) $input['version'] : null);
        } catch (ValidationException $e) {
            return $this->responder->render('admin/integrations/form', ['row' => $row, 'values' => $input, 'errors' => $e->errors()], status: 422);
        }
        $this->responder->success('Integration saved.');
        return $this->responder->redirect('admin.integrations.show', ['id' => $row['id']]);
    }

    public function toggle(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $enable = ((array) $request->getParsedBody())['enabled'] ?? '0';
        try {
            $this->integrations->setEnabled($this->actor(), $id, $enable === '1');
            $this->responder->success($enable === '1' ? 'Integration enabled.' : 'Integration disabled.');
        } catch (ValidationException $e) {
            $this->responder->error($e->getMessage() === 'Validation failed.' ? implode(' ', array_merge(...array_values($e->errors()))) : $e->getMessage());
        }
        return $this->responder->redirect('admin.integrations.show', ['id' => $id]);
    }

    public function security(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $in = (array) $request->getParsedBody();
        try {
            $this->integrations->updateSecurity($this->actor(), $id, isset($in['secret']) ? (string) $in['secret'] : null, ($in['clear_secret'] ?? '') === '1', ($in['allow_private_network'] ?? '') === '1');
            $this->responder->success('Security settings saved.');
        } catch (ValidationException $e) {
            $this->responder->error(implode(' ', array_merge(...array_values($e->errors()))));
        }
        return $this->responder->redirect('admin.integrations.show', ['id' => $id]);
    }

    public function test(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $result = $this->integrations->testConnection($this->actor(), $id);
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return $this->responder->fragment('admin/integrations/_test', ['result' => $result]);
        }
        $result['ok'] ? $this->responder->success('Connection OK (HTTP ' . $result['http_status'] . ', ' . $result['duration_ms'] . ' ms).')
            : $this->responder->error('Connection failed: ' . (string) $result['error']);
        return $this->responder->redirect('admin.integrations.show', ['id' => $id]);
    }

    public function resetCircuit(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $this->integrations->resetBreaker($this->actor(), $id);
        $this->responder->success('Circuit breaker reset (closed).');
        return $this->responder->redirect('admin.integrations.show', ['id' => $id]);
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $this->integrations->delete($this->actor(), (string) $route->getArgument('id'));
        $this->responder->success('Integration deleted.');
        return $this->responder->redirect('admin.integrations');
    }

    /** All recent outbound calls (any integration, incl. notification providers). */
    public function logs(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $query = $this->integrations->logsQuery();
        if (($q['integration'] ?? '') !== '') {
            $query->andWhere(['i.code' => (string) $q['integration']]);
        }
        if (($q['outcome'] ?? '') === 'error') {
            $query->andWhere(['not', ['l.error_class' => null]]);
        } elseif (($q['outcome'] ?? '') === 'ok') {
            $query->andWhere(['l.error_class' => null]);
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 50, (string) ($q['sort'] ?? ''), self::LOG_SORT, '-created_at');
        return $this->responder->render('admin/integrations/logs', [
            'page' => $page,
            'query' => array_intersect_key($q, ['integration' => 1, 'outcome' => 1, 'sort' => 1]),
            'codes' => $this->integrations->query()->select('code')->orderBy('code')->column(),
        ]);
    }

    public function log(CurrentRoute $route): ResponseInterface
    {
        $row = $this->integrations->logsQuery()->andWhere(['l.id' => (int) $route->getArgument('logId')])->one()
            ?? throw new \App\Shared\Domain\Exception\NotFoundException('Log entry not found.');
        return $this->responder->render('admin/integrations/log', ['log' => $row]);
    }

    /** @return array<string,mixed> */
    private static function formValues(array $row): array
    {
        $auth = (array) Sql::json($row['auth_config'] ?? null);
        $headers = Sql::json($row['headers'] ?? null);
        return $row + [
            'auth_username' => $auth['username'] ?? '',
            'api_key_header' => $auth['api_key_header'] ?? '',
            'hmac_header' => $auth['hmac_header'] ?? '',
            'hmac_timestamp_header' => $auth['hmac_timestamp_header'] ?? '',
            'headers' => $headers === [] ? '' : json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function actor(): string
    {
        return (string) $this->user->getId();
    }
}
