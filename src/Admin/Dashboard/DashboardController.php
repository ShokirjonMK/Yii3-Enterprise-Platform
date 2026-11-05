<?php

declare(strict_types=1);

namespace App\Admin\Dashboard;

use App\Platform\Monitoring\HealthChecker;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * 01 · Dashboard — live KPIs (auto-refresh via HTMX), dependency health, recent audit/security events.
 * All numbers come from real tables; nothing is mocked.
 */
final class DashboardController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ConnectionInterface $db,
        private readonly HealthChecker $health,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->responder->render('@admin/Dashboard/views/index', [
            'kpis' => $this->kpis(),
            'audit' => (new Query($this->db))->select(['occurred_at', 'action', 'result', 'actor_name', 'actor_id', 'entity_type', 'entity_id', 'category'])
                ->from('audit_log')->orderBy(['occurred_at' => SORT_DESC])->limit(8)->all(),
            'logins' => $this->db->createCommand(
                "SELECT date_trunc('hour', created_at) AS h, count(*) FILTER (WHERE success) AS ok, count(*) FILTER (WHERE NOT success) AS fail
                 FROM login_attempts WHERE created_at > now() - interval '24 hours' GROUP BY 1 ORDER BY 1",
            )->queryAll(),
        ]);
    }

    public function kpiFragment(): ResponseInterface
    {
        return $this->responder->fragment('@admin/Dashboard/views/_kpis', ['kpis' => $this->kpis()]);
    }

    public function healthFragment(): ResponseInterface
    {
        return $this->responder->fragment('@admin/Dashboard/views/_health', ['checks' => $this->health->checkAll()]);
    }

    /** @return array<string, int|float> */
    private function kpis(): array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT
              (SELECT count(*) FROM users WHERE deleted_at IS NULL) AS users_total,
              (SELECT count(*) FROM users WHERE deleted_at IS NULL AND status = 'active') AS users_active,
              (SELECT count(*) FROM tenants WHERE status = 'active') AS tenants,
              (SELECT count(*) FROM user_sessions WHERE revoked_at IS NULL AND expires_at > now() AND last_seen_at > now() - interval '30 minutes') AS sessions,
              (SELECT count(*) FROM jobs WHERE status = 'pending') AS jobs_pending,
              (SELECT count(*) FROM jobs WHERE status = 'running') AS jobs_running,
              (SELECT count(*) FROM jobs WHERE status IN ('failed','dead') AND updated_at > now() - interval '24 hours') AS jobs_failed,
              (SELECT count(*) FROM outbox WHERE status = 'pending') AS outbox_pending,
              (SELECT count(*) FROM kafka_dead_letters WHERE replayed_at IS NULL) AS dlq,
              (SELECT count(*) FROM incidents WHERE status NOT IN ('resolved','closed')) AS incidents_open,
              (SELECT count(*) FROM login_attempts WHERE NOT success AND created_at > now() - interval '24 hours') AS login_failures,
              (SELECT count(*) FROM workers WHERE heartbeat_at > now() - interval '90 seconds' AND status NOT IN ('stopped','lost')) AS workers_alive,
              (SELECT count(*) FROM approval_requests WHERE status = 'pending') AS approvals_pending,
              (SELECT count(*) FROM audit_log WHERE occurred_at > now() - interval '24 hours') AS audit_24h
            SQL)->queryOne();
        return array_map('intval', $row ?? []);
    }
}
