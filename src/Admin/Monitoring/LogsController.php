<?php

declare(strict_types=1);

namespace App\Admin\Monitoring;

use App\Platform\FeatureFlags\FeatureFlagService;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Http\Responder;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;

/**
 * 17 · Monitoring → Application logs: warning / error / critical records of every process
 * (web, API, workers, consumers) stored by DatabaseLogTarget (flag logging.database). Already redacted.
 *
 * Author: ShokirjonMK
 */
final class LogsController
{
    private const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning'];

    public function __construct(
        private readonly Responder $responder,
        private readonly ConnectionInterface $db,
        private readonly FeatureFlagService $flags,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $f = [
            'level' => (string) ($q['level'] ?? ''),
            'channel' => (string) ($q['channel'] ?? ''),
            'q' => trim((string) ($q['q'] ?? '')),
            'correlation' => trim((string) ($q['correlation'] ?? '')),
        ];
        $query = (new Query($this->db))->select(['id', 'occurred_at', 'level', 'category', 'message', 'channel', 'user_id', 'correlation_id', 'host'])->from('app_logs');
        if (in_array($f['level'], self::LEVELS, true)) {
            $query->andWhere(['level' => $f['level']]);
        }
        if (in_array($f['channel'], ['web', 'api', 'cli', 'worker', 'consumer'], true)) {
            $query->andWhere(['channel' => $f['channel']]);
        }
        if ($f['q'] !== '') {
            $query->andWhere(Sql::search($f['q'], ['message', 'category']));
        }
        if ($f['correlation'] !== '') {
            $query->andWhere(['correlation_id' => $f['correlation']]);
        }
        $page = Paginator::paginate($query, (int) ($q['page'] ?? 1), 50, '-occurred_at', ['occurred_at' => 'occurred_at'], '-occurred_at');
        $counts = $this->db->createCommand("SELECT level, count(*) c FROM app_logs WHERE occurred_at > now() - interval '24 hours' GROUP BY level")->queryAll();
        return $this->responder->render('@admin/Monitoring/views/logs', [
            'page' => $page, 'filters' => $f, 'levels' => self::LEVELS,
            'counts' => array_column($counts, 'c', 'level'),
            'enabled' => $this->flags->isEnabled('logging.database'),
        ]);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $row = (new Query($this->db))->from('app_logs')->where(['id' => (int) $route->getArgument('id')])->one()
            ?? throw new NotFoundException('Log record not found.');
        return $this->responder->render('@admin/Monitoring/views/log-view', ['log' => $row]);
    }
}
