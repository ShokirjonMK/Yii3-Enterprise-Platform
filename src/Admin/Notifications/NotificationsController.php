<?php

declare(strict_types=1);

namespace App\Admin\Notifications;

use App\Platform\Access\RbacCatalog;
use App\Platform\Integrations\NotificationAdminService;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 08 · Messaging & Notifications — channels (enable/disable, health, test send to self), templates CRUD with
 * live preview, deliveries log with filters/retry, broadcast to a role.
 */
final class NotificationsController
{
    private const TEMPLATE_SORT = ['code' => 't.code', 'channel' => 't.channel', 'locale' => 't.locale', 'updated_at' => 't.updated_at'];
    private const DELIVERY_SORT = ['created_at' => 'n.created_at', 'status' => 'n.status', 'channel' => 'n.channel', 'attempts' => 'n.attempts'];
    private const STATUSES = ['queued', 'sending', 'sent', 'delivered', 'failed', 'read', 'skipped'];

    public function __construct(
        private readonly Responder $responder,
        private readonly NotificationAdminService $service,
        private readonly RbacCatalog $catalog,
        private readonly CurrentUser $user,
    ) {}

    // ------------------------------------------------------------------------------------------ channels

    public function index(): ResponseInterface
    {
        return $this->responder->render('@admin/Notifications/views/index', ['channels' => $this->service->channels()]);
    }

    public function health(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        $result = $this->service->checkHealth($this->actor(), $code);
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return $this->responder->fragment('@admin/Notifications/views/_health', ['code' => $code, 'result' => $result]);
        }
        $this->responder->info(sprintf('%s: %s', $code, $result['status']));
        return $this->responder->redirect('admin.notifications');
    }

    public function toggle(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        $enable = (((array) $request->getParsedBody())['enabled'] ?? '0') === '1';
        try {
            $this->service->setEnabled($this->actor(), $code, $enable);
            $this->responder->success($enable ? 'Channel enabled.' : 'Channel disabled.');
        } catch (ValidationException|ConflictException $e) {
            $this->responder->error($e instanceof ValidationException ? implode(' ', array_merge(...array_values($e->errors()))) : $e->getMessage());
        }
        return $this->responder->redirect('admin.notifications');
    }

    public function testSend(CurrentRoute $route): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        try {
            $n = $this->service->testSend($this->actor(), $code);
            $status = (string) ($n['status'] ?? '');
            $status === 'skipped'
                ? $this->responder->error(sprintf('Test notification skipped: %s.', (string) $n['last_error']))
                : $this->responder->success(sprintf('Test notification %s (%s).', $status === 'queued' ? 'queued for delivery' : $status, $code));
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.notifications');
    }

    // ------------------------------------------------------------------------------------------ templates

    public function templates(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $query = $this->service->templatesQuery();
        if (($q['q'] ?? '') !== '') {
            $query->andWhere(Sql::search((string) $q['q'], ['t.code', 't.subject']));
        }
        foreach (['channel', 'locale'] as $f) {
            if (($q[$f] ?? '') !== '') {
                $query->andWhere(['t.' . $f => (string) $q[$f]]);
            }
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 50, (string) ($q['sort'] ?? ''), self::TEMPLATE_SORT, 'code');
        return $this->responder->render('@admin/Notifications/views/templates', [
            'page' => $page, 'query' => array_intersect_key($q, ['q' => 1, 'channel' => 1, 'locale' => 1, 'sort' => 1]),
            'channels' => array_column($this->service->channels(), 'code'),
        ]);
    }

    public function createTemplate(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        return $this->renderTemplateForm(null, ['code' => $q['code'] ?? '', 'channel' => $q['channel'] ?? 'inapp', 'locale' => $q['locale'] ?? 'en'], []);
    }

    public function storeTemplate(ServerRequestInterface $request): ResponseInterface
    {
        $in = (array) $request->getParsedBody();
        try {
            $id = $this->service->saveTemplate($this->actor(), null, $in);
        } catch (ValidationException $e) {
            return $this->renderTemplateForm(null, $in, $e->errors(), 422);
        }
        $this->responder->success('Template created.');
        return $this->responder->redirect('admin.notifications.templates.edit', ['id' => $id]);
    }

    public function editTemplate(CurrentRoute $route): ResponseInterface
    {
        $row = $this->service->template((string) $route->getArgument('id'));
        return $this->renderTemplateForm($row, $row, []);
    }

    public function updateTemplate(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $row = $this->service->template((string) $route->getArgument('id'));
        $in = (array) $request->getParsedBody();
        try {
            $this->service->saveTemplate($this->actor(), (string) $row['id'], $in);
        } catch (ValidationException $e) {
            return $this->renderTemplateForm($row, $in, $e->errors(), 422);
        }
        $this->responder->success('Template saved.');
        return $this->responder->redirect('admin.notifications.templates.edit', ['id' => $row['id']]);
    }

    public function deleteTemplate(CurrentRoute $route): ResponseInterface
    {
        $this->service->deleteTemplate($this->actor(), (string) $route->getArgument('id'));
        $this->responder->success('Template deleted.');
        return $this->responder->redirect('admin.notifications.templates');
    }

    /** HTMX: renders the draft (or the stored template through Notifier::render) with sample parameters. */
    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $in = (array) $request->getParsedBody();
        $params = json_decode((string) ($in['sample'] ?? ''), true);
        $error = null;
        if (!is_array($params)) {
            $error = trim((string) ($in['sample'] ?? '')) === '' ? null : 'Sample parameters must be a JSON object.';
            $params = [];
        }
        $params = array_map(static fn($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $params) + ['name' => 'Jane Doe'];
        $preview = $this->service->preview(
            (string) ($in['code'] ?? ''),
            (string) ($in['channel'] ?? 'inapp'),
            (string) ($in['locale'] ?? 'en'),
            $params,
            isset($in['subject']) ? (string) $in['subject'] : null,
            isset($in['body']) ? (string) $in['body'] : null,
        );
        return $this->responder->fragment('@admin/Notifications/views/_preview', ['preview' => $preview, 'error' => $error]);
    }

    // ------------------------------------------------------------------------------------------ deliveries

    public function deliveries(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $query = $this->service->deliveriesQuery();
        if (in_array($q['status'] ?? '', self::STATUSES, true)) {
            $query->andWhere(['n.status' => $q['status']]);
        }
        if (($q['channel'] ?? '') !== '') {
            $query->andWhere(['n.channel' => (string) $q['channel']]);
        }
        if (($q['template'] ?? '') !== '') {
            $query->andWhere(['n.template_code' => (string) $q['template']]);
        }
        if (($q['q'] ?? '') !== '') {
            $query->andWhere(Sql::search((string) $q['q'], ['n.recipient', 'n.subject', 'u.username']));
        }
        foreach (['from' => '>=', 'to' => '<'] as $f => $op) {
            if (($q[$f] ?? '') !== '' && strtotime((string) $q[$f]) !== false) {
                $query->andWhere([$op, 'n.created_at', date('Y-m-d H:i:sP', strtotime((string) $q[$f] . ($f === 'to' ? ' +1 day' : '')))]);
            }
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 50, (string) ($q['sort'] ?? ''), self::DELIVERY_SORT, '-created_at');
        return $this->responder->render('@admin/Notifications/views/deliveries', [
            'page' => $page, 'statuses' => self::STATUSES, 'channels' => array_column($this->service->channels(), 'code'),
            'query' => array_intersect_key($q, ['status' => 1, 'channel' => 1, 'template' => 1, 'q' => 1, 'from' => 1, 'to' => 1, 'sort' => 1]),
        ]);
    }

    public function delivery(CurrentRoute $route): ResponseInterface
    {
        return $this->responder->render('@admin/Notifications/views/delivery', ['n' => $this->service->delivery((string) $route->getArgument('id'))]);
    }

    public function retry(CurrentRoute $route): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        try {
            $this->service->retry($this->actor(), $id);
            $this->responder->success('Delivery re-queued.');
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.notifications.delivery', ['id' => $id]);
    }

    public function retryFailed(ServerRequestInterface $request): ResponseInterface
    {
        $channel = (string) (((array) $request->getParsedBody())['channel'] ?? '');
        $n = $this->service->retryFailed($this->actor(), $channel === '' ? null : $channel);
        $this->responder->success(sprintf('%d failed deliveries re-queued.', $n));
        return $this->responder->redirect('admin.notifications.deliveries', [], ['status' => 'failed']);
    }

    // ------------------------------------------------------------------------------------------ broadcast

    public function broadcastForm(): ResponseInterface
    {
        return $this->renderBroadcast(['channels' => ['inapp']], [], null);
    }

    public function broadcast(ServerRequestInterface $request): ResponseInterface
    {
        $in = (array) $request->getParsedBody();
        try {
            $result = $this->service->broadcast(
                $this->actor(),
                (string) ($in['role'] ?? ''),
                (string) ($in['template_code'] ?? ''),
                (string) ($in['subject'] ?? ''),
                (string) ($in['message'] ?? ''),
                array_values((array) ($in['channels'] ?? [])),
                'broadcast',
            );
        } catch (ValidationException $e) {
            return $this->renderBroadcast($in, $e->errors(), null, 422);
        }
        return $this->renderBroadcast(['channels' => ['inapp']], [], $result);
    }

    private function renderBroadcast(array $values, array $errors, ?array $result, int $status = 200): ResponseInterface
    {
        return $this->responder->render('@admin/Notifications/views/broadcast', [
            'values' => $values, 'errors' => $errors, 'result' => $result, 'roles' => array_keys($this->catalog->roles()),
            'channels' => $this->service->channels(),
        ], status: $status);
    }

    private function renderTemplateForm(?array $row, array $values, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render('@admin/Notifications/views/template-form', [
            'row' => $row, 'values' => $values, 'errors' => $errors,
            'channels' => array_column($this->service->channels(), 'code'), 'locales' => NotificationAdminService::LOCALES,
        ], status: $status);
    }

    private function actor(): string
    {
        return (string) $this->user->getId();
    }
}
