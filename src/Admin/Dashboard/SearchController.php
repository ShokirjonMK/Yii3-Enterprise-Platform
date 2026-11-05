<?php

declare(strict_types=1);

namespace App\Admin\Dashboard;

use App\Admin\Menu\AdminMenu;
use App\Platform\Access\AccessService;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\User\CurrentUser;

/**
 * Global admin search across sections the user may access (users, tenants, jobs, audit, incidents, menu).
 */
final class SearchController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly CurrentUser $currentUser,
        private readonly \App\Platform\FeatureFlags\FeatureFlagService $flags,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $uid = $this->currentUser->getId();
        $results = [];
        if (mb_strlen($q) >= 2) {
            foreach (AdminMenu::flat() as $item) {
                $flag = \App\Platform\FeatureFlags\FeatureRegistry::flagForMenu($item['route']);
                $on = str_starts_with($item['route'], 'admin.devtools')
                    ? \App\Admin\DevTools\DevToolsController::available($this->flags)
                    : ($flag === null || $this->flags->isEnabled($flag));
                if ($on && stripos($item['label'], $q) !== false && $this->access->can($uid, $item['permission'])) {
                    $results['Sections'][] = ['label' => $item['label'], 'route' => $item['route'], 'args' => [], 'meta' => sprintf('%02d', $item['n'])];
                }
            }
            if ($this->access->can($uid, 'users.view')) {
                foreach ((new Query($this->db))->select(['id', 'username', 'full_name', 'email'])->from('users')->where(['deleted_at' => null])
                    ->andWhere(Sql::search($q, ['username', 'full_name', 'email']))->limit(10)->all() as $u) {
                    $results['Users'][] = ['label' => $u['full_name'] ?: $u['username'], 'route' => 'admin.users.view', 'args' => ['id' => $u['id']], 'meta' => $u['username']];
                }
            }
            if ($this->access->can($uid, 'tenants.view')) {
                foreach ((new Query($this->db))->select(['id', 'code', 'name'])->from('tenants')->where(Sql::search($q, ['code', 'name']))->limit(10)->all() as $t) {
                    $results['Tenants'][] = ['label' => $t['name'], 'route' => 'admin.tenants.view', 'args' => ['id' => $t['id']], 'meta' => $t['code']];
                }
            }
            if ($this->access->can($uid, 'incidents.view')) {
                foreach ((new Query($this->db))->select(['id', 'number', 'title', 'status'])->from('incidents')->where(Sql::search($q, ['title', 'number']))->limit(10)->all() as $i) {
                    $results['Incidents'][] = ['label' => '#' . $i['number'] . ' ' . $i['title'], 'route' => 'admin.incidents.view', 'args' => ['id' => $i['id']], 'meta' => $i['status']];
                }
            }
            if ($this->access->can($uid, 'audit.view')) {
                foreach ((new Query($this->db))->select(['id', 'action', 'occurred_at'])->from('audit_log')->where(Sql::search($q, ['action', 'entity_id', 'correlation_id']))->orderBy(['occurred_at' => SORT_DESC])->limit(10)->all() as $a) {
                    $results['Audit'][] = ['label' => $a['action'], 'route' => 'admin.audit', 'args' => [], 'query' => ['q' => $q], 'meta' => $a['occurred_at']];
                }
            }
        }
        return $this->responder->render('@admin/Dashboard/views/search', ['q' => $q, 'results' => $results]);
    }
}
