<?php

declare(strict_types=1);

namespace App\Web\Action\Notifications;

use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * In-app notification inbox (channel "inapp"). Users only ever see their own rows.
 */
final class InboxController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly ConnectionInterface $db,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $query = (new Query($this->db))->from('notifications')
            ->where(['user_id' => $this->currentUser->getId(), 'channel' => 'inapp']);
        if (($q['filter'] ?? '') === 'unread') {
            $query->andWhere(['read_at' => null]);
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 20, '-created_at', ['created_at' => 'created_at']);
        return $this->responder->render('web/notifications/index', ['page' => $page, 'filter' => $q['filter'] ?? 'all'], 'web');
    }

    public function badge(): ResponseInterface
    {
        $count = (int) (new Query($this->db))->from('notifications')
            ->where(['user_id' => $this->currentUser->getId(), 'channel' => 'inapp', 'read_at' => null])->count();
        return $this->responder->json(['unread' => $count]);
    }

    public function markRead(CurrentRoute $route): ResponseInterface
    {
        $this->db->createCommand()->update('notifications', ['read_at' => new Expression('now()'), 'status' => 'read'], [
            'id' => (string) $route->getArgument('id'),
            'user_id' => $this->currentUser->getId(),
            'read_at' => null,
        ])->execute();
        return $this->responder->redirect('web.notifications');
    }

    public function markAllRead(): ResponseInterface
    {
        $this->db->createCommand()->update('notifications', ['read_at' => new Expression('now()'), 'status' => 'read'], [
            'user_id' => $this->currentUser->getId(), 'channel' => 'inapp', 'read_at' => null,
        ])->execute();
        $this->responder->success('All notifications marked as read.');
        return $this->responder->redirect('web.notifications');
    }
}
