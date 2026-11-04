<?php

declare(strict_types=1);

namespace App\Admin\Action\Integrations;

use App\Platform\Integrations\Webhook\WebhookService;
use App\Platform\Queue\HandlerRegistry;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Http\Responder;
use App\Shared\Infrastructure\Logging\Redactor;
use App\Shared\Infrastructure\Database\Sql;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 07 · Integrations → inbound webhook endpoints and received events. The signing secret is displayed exactly once
 * (creation / rotation) and is never stored in the session or flash.
 */
final class WebhooksController
{
    private const SORT = ['code' => 'code', 'name' => 'name', 'last_received_at' => 'last_received_at', 'created_at' => 'created_at'];
    private const EVENT_SORT = ['received_at' => 'received_at', 'id' => 'id'];

    public function __construct(
        private readonly Responder $responder,
        private readonly WebhookService $webhooks,
        private readonly HandlerRegistry $handlers,
        private readonly CurrentUser $user,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $query = $this->webhooks->query()->select(['webhook_endpoints.*', new \Yiisoft\Db\Expression\Expression('(SELECT count(*) FROM webhook_events e WHERE e.endpoint_id = webhook_endpoints.id) AS events')]);
        if (($q['q'] ?? '') !== '') {
            $query->andWhere(Sql::search((string) $q['q'], ['code', 'name']));
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 25, (string) ($q['sort'] ?? ''), self::SORT, 'code');
        return $this->responder->render('admin/integrations/webhooks/index', ['page' => $page, 'query' => array_intersect_key($q, ['q' => 1, 'sort' => 1])]);
    }

    public function create(): ResponseInterface
    {
        return $this->responder->render('admin/integrations/webhooks/form', ['row' => null, 'values' => ['tolerance_seconds' => 300], 'errors' => [], 'jobTypes' => $this->handlers->types()]);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = (array) $request->getParsedBody();
        try {
            $created = $this->webhooks->create($this->actor(), $input);
        } catch (ValidationException $e) {
            return $this->responder->render('admin/integrations/webhooks/form', ['row' => null, 'values' => $input, 'errors' => $e->errors(), 'jobTypes' => $this->handlers->types()], status: 422);
        }
        return $this->responder->render('admin/integrations/webhooks/secret', ['row' => $this->webhooks->find($created['id']), 'secret' => $created['secret'], 'rotated' => false]);
    }

    public function show(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->webhooks->find((string) $route->getArgument('id'));
        $q = $request->getQueryParams();
        $page = Paginator::paginate($this->webhooks->eventsQuery((string) $row['id']), (int) ($q['page'] ?? 1), 25, (string) ($q['sort'] ?? ''), self::EVENT_SORT, '-received_at');
        return $this->responder->render('admin/integrations/webhooks/show', ['row' => $row, 'page' => $page, 'query' => array_intersect_key($q, ['sort' => 1])]);
    }

    public function edit(CurrentRoute $route): ResponseInterface
    {
        $row = $this->webhooks->find((string) $route->getArgument('id'));
        return $this->responder->render('admin/integrations/webhooks/form', ['row' => $row, 'values' => $row, 'errors' => [], 'jobTypes' => $this->handlers->types()]);
    }

    public function update(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->webhooks->find((string) $route->getArgument('id'));
        $input = (array) $request->getParsedBody();
        try {
            $this->webhooks->update($this->actor(), (string) $row['id'], $input);
        } catch (ValidationException $e) {
            return $this->responder->render('admin/integrations/webhooks/form', ['row' => $row, 'values' => $input, 'errors' => $e->errors(), 'jobTypes' => $this->handlers->types()], status: 422);
        }
        $this->responder->success('Webhook endpoint saved.');
        return $this->responder->redirect('admin.integrations.webhooks.show', ['id' => $row['id']]);
    }

    public function toggle(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $enable = (((array) $request->getParsedBody())['enabled'] ?? '0') === '1';
        $this->webhooks->setEnabled($this->actor(), $id, $enable);
        $this->responder->success($enable ? 'Webhook endpoint enabled.' : 'Webhook endpoint disabled.');
        return $this->responder->redirect('admin.integrations.webhooks.show', ['id' => $id]);
    }

    public function rotate(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $secret = $this->webhooks->rotateSecret($this->actor(), $id);
        return $this->responder->render('admin/integrations/webhooks/secret', ['row' => $this->webhooks->find($id), 'secret' => $secret, 'rotated' => true]);
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $this->webhooks->delete($this->actor(), (string) $route->getArgument('id'));
        $this->responder->success('Webhook endpoint deleted.');
        return $this->responder->redirect('admin.integrations.webhooks');
    }

    public function event(CurrentRoute $route): ResponseInterface
    {
        $row = $this->webhooks->find((string) $route->getArgument('id'));
        $event = $this->webhooks->eventsQuery((string) $row['id'])->andWhere(['id' => (int) $route->getArgument('eventId')])->one()
            ?? throw new NotFoundException('Event not found.');
        $event['payload'] = Redactor::redact(Sql::json($event['payload']));
        return $this->responder->render('admin/integrations/webhooks/event', ['row' => $row, 'event' => $event]);
    }

    private function actor(): string
    {
        return (string) $this->user->getId();
    }
}
