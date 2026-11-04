<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Point-in-time gauges computed at scrape time from PostgreSQL (cheap aggregate queries).
 */
final class MetricsCollector
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Metrics $metrics,
    ) {}

    public function collect(): void
    {
        try {
            // Zero-fill known label combinations so gauges drop to 0 instead of keeping stale values
            $queues = $this->db->createCommand("SELECT DISTINCT queue FROM jobs UNION SELECT queue FROM queue_state")->queryColumn();
            $counts = [];
            foreach ($this->db->createCommand("SELECT queue, status, count(*) AS c FROM jobs WHERE status IN ('pending','running','failed','dead') GROUP BY queue, status")->queryAll() as $r) {
                $counts[$r['queue']][$r['status']] = (float) $r['c'];
            }
            foreach ($queues as $q) {
                foreach (['pending', 'running', 'failed', 'dead'] as $st) {
                    $this->metrics->gauge('queue_jobs', 'Jobs by queue and status', $counts[$q][$st] ?? 0.0, ['queue' => (string) $q, 'status' => $st]);
                }
            }
            $oldest = $this->db->createCommand("SELECT COALESCE(EXTRACT(EPOCH FROM now() - min(available_at)), 0) FROM jobs WHERE status = 'pending' AND available_at <= now()")->queryScalar();
            $this->metrics->gauge('queue_oldest_pending_seconds', 'Age of the oldest runnable job', (float) $oldest);

            $outbox = $this->db->createCommand("SELECT status, count(*) AS c FROM outbox WHERE status <> 'published' GROUP BY status")->queryAll();
            foreach (['pending', 'failed'] as $st) {
                $this->metrics->gauge('outbox_events', 'Unpublished outbox events', 0, ['status' => $st]);
            }
            foreach ($outbox as $r) {
                $this->metrics->gauge('outbox_events', 'Unpublished outbox events', (float) $r['c'], ['status' => $r['status']]);
            }
            $this->metrics->gauge('kafka_dead_letters', 'Dead letters not yet replayed', (float) $this->db->createCommand('SELECT count(*) FROM kafka_dead_letters WHERE replayed_at IS NULL')->queryScalar());

            $alive = ['queue' => 0.0, 'kafka' => 0.0];
            foreach ($this->db->createCommand("SELECT type, count(*) AS c FROM workers WHERE heartbeat_at > now() - interval '90 seconds' AND status NOT IN ('stopped','lost') GROUP BY type")->queryAll() as $r) {
                $alive[$r['type']] = (float) $r['c'];
            }
            foreach ($alive as $type => $c) {
                $this->metrics->gauge('workers_alive', 'Live worker processes', $c, ['type' => (string) $type]);
            }
            $this->metrics->gauge('login_failures_5m', 'Failed logins in the last 5 minutes', (float) $this->db->createCommand("SELECT count(*) FROM login_attempts WHERE NOT success AND created_at > now() - interval '5 minutes'")->queryScalar());
            $sev = ['sev1' => 0.0, 'sev2' => 0.0, 'sev3' => 0.0, 'sev4' => 0.0];
            foreach ($this->db->createCommand("SELECT severity, count(*) AS c FROM incidents WHERE status NOT IN ('resolved','closed') GROUP BY severity")->queryAll() as $r) {
                $sev[$r['severity']] = (float) $r['c'];
            }
            foreach ($sev as $severity => $c) {
                $this->metrics->gauge('incidents_open', 'Open incidents', $c, ['severity' => $severity]);
            }
            $this->metrics->gauge('sessions_active', 'Active interactive sessions', (float) $this->db->createCommand("SELECT count(*) FROM user_sessions WHERE revoked_at IS NULL AND expires_at > now() AND last_seen_at > now() - interval '30 minutes'")->queryScalar());
            $this->metrics->gauge('db_connections', 'PostgreSQL connections', (float) $this->db->createCommand('SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()')->queryScalar());
            $this->metrics->gauge('collector_errors', 'Metrics collector failures', 0);
        } catch (Throwable) {
            $this->metrics->gauge('collector_errors', 'Metrics collector failures', 1);
        }
    }
}
