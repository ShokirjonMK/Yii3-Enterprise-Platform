<?php

declare(strict_types=1);

namespace App\Admin\Audit;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Http\Responder;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Access\AccessService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\User\CurrentUser;

/**
 * 20 · Audit → User activity: every authenticated page view / API call (flag audit.user_activity).
 * Tenant-scoped for administrators without tenants.cross.
 *
 * Author: ShokirjonMK
 */
final class ActivityController
{
    private const SORTABLE = ['occurred_at' => 'occurred_at', 'duration_ms' => 'duration_ms', 'status' => 'status', 'username' => 'username'];

    public function __construct(
        private readonly Responder $responder,
        private readonly ConnectionInterface $db,
        private readonly FeatureFlagService $flags,
        private readonly CurrentUser $currentUser,
        private readonly AccessService $access,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $f = [
            'user' => trim((string) ($q['user'] ?? '')),
            'path' => trim((string) ($q['path'] ?? '')),
            'channel' => (string) ($q['channel'] ?? ''),
            'method' => (string) ($q['method'] ?? ''),
            'status' => (string) ($q['status'] ?? ''),
            'from' => (string) ($q['from'] ?? ''),
            'to' => (string) ($q['to'] ?? ''),
        ];
        $query = (new Query($this->db))->from('user_activity');
        $identity = $this->currentUser->getIdentity();
        if ($identity instanceof Identity && !$this->access->can($identity->user->id, 'tenants.cross')) {
            $query->andWhere(['tenant_id' => $identity->user->tenantId]);
        }
        if ($f['user'] !== '') {
            $query->andWhere(\App\Shared\Domain\Uuid::isValid($f['user']) ? ['user_id' => $f['user']] : Sql::search($f['user'], ['username']));
        }
        if ($f['path'] !== '') {
            $query->andWhere(Sql::search($f['path'], ['path', 'route']));
        }
        if (in_array($f['channel'], ['web', 'api'], true)) {
            $query->andWhere(['channel' => $f['channel']]);
        }
        if (in_array($f['method'], ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            $query->andWhere(['method' => $f['method']]);
        }
        if (in_array($f['status'], ['2', '3', '4', '5'], true)) {
            $query->andWhere(['between', 'status', (int) $f['status'] * 100, (int) $f['status'] * 100 + 99]);
        }
        if (strtotime($f['from']) !== false && $f['from'] !== '') {
            $query->andWhere(['>=', 'occurred_at', $f['from']]);
        }
        if (strtotime($f['to']) !== false && $f['to'] !== '') {
            $query->andWhere(['<', 'occurred_at', date('Y-m-d', strtotime($f['to'] . ' +1 day'))]);
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 50, $q['sort'] ?? null, self::SORTABLE, '-occurred_at');
        $since = date(DATE_ATOM, time() - 86400);
        $stats = $this->db->createCommand(
            'SELECT count(*) AS requests, count(DISTINCT user_id) AS users,
                    COALESCE(percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms), 0)::int AS p95,
                    count(*) FILTER (WHERE status >= 400) AS errors
             FROM user_activity WHERE occurred_at >= :s',
            [':s' => $since],
        )->queryOne();
        return $this->responder->render('@admin/Audit/views/activity', [
            'page' => $page, 'filters' => $f, 'stats' => $stats,
            'enabled' => $this->flags->isEnabled('audit.user_activity'),
        ]);
    }
}
