<?php

declare(strict_types=1);

namespace App\Admin\Incidents;

use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Incidents\IncidentService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 24 · Incident Management.
 */
final class IncidentsController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly IncidentService $incidents,
        private readonly ConnectionInterface $db,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        return $this->renderView($this->incidents->get($id));
    }

    public function open(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        try {
            $id = $this->incidents->open($this->actor(), $body);
        } catch (ValidationException $e) {
            return $this->renderIndex($request, $e->errors(), $body, 422);
        }
        $this->responder->success('Incident opened and on-call users notified.');
        return $this->responder->redirect('admin.incidents.view', ['id' => $id]);
    }

    public function acknowledge(CurrentRoute $route): ResponseInterface
    {
        return $this->act($route, fn(string $id) => $this->incidents->acknowledge($this->actor(), $id), 'Incident acknowledged.');
    }

    public function transition(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $b = (array) $request->getParsedBody();
        return $this->act($route, fn(string $id) => $this->incidents->transition($this->actor(), $id, (string) ($b['status'] ?? ''), (string) ($b['note'] ?? '')), 'Status updated.');
    }

    public function note(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $b = (array) $request->getParsedBody();
        return $this->act($route, fn(string $id) => $this->incidents->addNote($this->actor(), $id, (string) ($b['body'] ?? '')), 'Note added.');
    }

    public function commander(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $b = (array) $request->getParsedBody();
        $uid = trim((string) ($b['commander_id'] ?? ''));
        return $this->act($route, fn(string $id) => $this->incidents->assignCommander($this->actor(), $id, $uid === '' ? null : $uid), 'Incident commander updated.');
    }

    public function postmortem(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $b = (array) $request->getParsedBody();
        return $this->act($route, fn(string $id) => $this->incidents->savePostmortem($this->actor(), $id, (string) ($b['postmortem'] ?? '')), 'Postmortem saved.');
    }

    private function act(CurrentRoute $route, callable $fn, string $ok): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        try {
            $fn($id);
            $this->responder->success($ok);
        } catch (ValidationException $e) {
            $this->responder->error(implode(' ', array_merge(...array_values($e->errors()))));
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.incidents.view', ['id' => $id]);
    }

    private function renderView(array $incident): ResponseInterface
    {
        return $this->responder->render('@admin/Incidents/views/view', [
            'i' => $incident,
            'timeline' => $this->incidents->timeline((string) $incident['id']),
            'transitions' => IncidentService::TRANSITIONS[$incident['status']] ?? [],
            'oncall' => $this->oncallUsers(),
        ]);
    }

    private function renderIndex(ServerRequestInterface $request, array $errors = [], array $old = [], int $status = 200): ResponseInterface
    {
        $q = $request->getQueryParams();
        $filters = ['status' => (string) ($q['status'] ?? 'active'), 'severity' => (string) ($q['severity'] ?? ''), 'q' => mb_substr(trim((string) ($q['q'] ?? '')), 0, 100)];
        return $this->responder->render('@admin/Incidents/views/index', [
            'page' => $this->incidents->list($filters, (int) ($q['page'] ?? 1), (string) ($q['sort'] ?? '')),
            'filters' => $filters,
            'counts' => $this->incidents->counts(),
            'oncall' => $this->oncallUsers(),
            'errors' => $errors,
            'old' => $old,
        ], status: $status);
    }

    /** @return list<array{id:string, username:string, full_name:string}> */
    private function oncallUsers(): array
    {
        $ids = $this->incidents->onCallUserIds(200);
        return $ids === [] ? [] : (new Query($this->db))->select(['id', 'username', 'full_name'])->from('users')
            ->where(['id' => $ids])->orderBy(['username' => SORT_ASC])->all();
    }

    private function actor(): User
    {
        $identity = $this->currentUser->getIdentity();
        if (!$identity instanceof Identity) {
            throw new AccessDeniedException();
        }
        return $identity->user;
    }
}
