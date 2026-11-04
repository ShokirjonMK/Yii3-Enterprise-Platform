<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

use App\Platform\Infrastructure\ExternalServiceException;
use App\Platform\Infrastructure\OpsClients;
use Prometheus\MetricFamilySamples;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * 17 · Monitoring data source selection:
 *   - Prometheus reachable (PROMETHEUS_URL) → PromQL over all scraped instances + exporters (PG/Redis/Kafka)
 *   - otherwise → the application's own Prometheus registry (Redis-backed, cumulative since the last
 *     metrics reset, all PHP processes of this deployment) + live database gauges.
 * The active source is always returned and shown, so numbers are never presented as something they are not.
 */
final class ObservabilityService
{
    public const NS = 'platform_';

    public function __construct(
        private readonly OpsClients $clients,
        private readonly Metrics $metrics,
        private readonly MetricsCollector $collector,
        private readonly ConnectionInterface $db,
    ) {}

    public function overview(): array
    {
        $prom = $this->clients->prometheus();
        $reachable = $prom->isReachable();
        $data = $reachable ? $this->fromPrometheus($prom) : $this->fromLocal();
        $data['prometheus'] = ['configured' => $prom->isConfigured(), 'url' => $prom->baseUrl(), 'reachable' => $reachable];
        $data['alerts'] = $this->alerts();
        $data['links'] = $this->clients->links();
        $data['db'] = $this->dbGauges();
        $data['generated_at'] = gmdate(DATE_ATOM);
        return $data;
    }

    /** @return array{configured:bool, reachable:bool, url:?string, items:list<array>, error:?string} */
    public function alerts(): array
    {
        $am = $this->clients->alertmanager();
        $out = ['configured' => $am->isConfigured(), 'reachable' => false, 'url' => $am->baseUrl(), 'items' => [], 'error' => null];
        if (!$am->isConfigured()) {
            return $out;
        }
        try {
            $out['items'] = $am->activeAlerts();
            $out['reachable'] = true;
        } catch (ExternalServiceException $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    private function fromPrometheus(PrometheusClient $p): array
    {
        $q = static function (string $promql) use ($p): ?float {
            try {
                return $p->scalar($promql);
            } catch (ExternalServiceException) {
                return null;
            }
        };
        $range = static function (string $promql) use ($p): ?array {
            try {
                $end = time();
                $r = $p->queryRange($promql, $end - 3600, $end, 60);
                return $r === [] ? null : array_map(static fn($v) => is_finite($v[1]) ? $v[1] : 0.0, $r[0]['values']);
            } catch (ExternalServiceException) {
                return null;
            }
        };
        $rps = 'sum(rate(platform_http_requests_total[5m]))';
        $err = 'sum(rate(platform_http_requests_total{status=~"5.."}[5m])) / clamp_min(sum(rate(platform_http_requests_total[5m])), 1e-9) * 100';
        $p95 = 'histogram_quantile(0.95, sum by (le) (rate(platform_http_request_duration_seconds_bucket[5m]))) * 1000';
        $p99 = 'histogram_quantile(0.99, sum by (le) (rate(platform_http_request_duration_seconds_bucket[5m]))) * 1000';
        $tiles = [
            self::tile('rps', 'Requests / s', $q($rps), 'req/s', 'rate over 5 min', $range($rps), 2),
            self::tile('errors', 'Error rate (5xx)', $q($err), '%', 'share of 5xx responses', $range($err), 2),
            self::tile('p95', 'Latency p95', $q($p95), 'ms', 'histogram_quantile 0.95', $range($p95), 0),
            self::tile('p99', 'Latency p99', $q($p99), 'ms', 'histogram_quantile 0.99', $range($p99), 0),
            self::tile('queue', 'Queue depth', $q('sum(platform_queue_jobs{status="pending"})'), 'jobs', 'pending jobs (all queues)', $range('sum(platform_queue_jobs{status="pending"})'), 0),
            self::tile('lag', 'Consumer lag', $q('sum(kafka_consumergroup_lag)'), 'msgs', 'kafka-exporter, all groups', $range('sum(kafka_consumergroup_lag)'), 0),
            self::tile('logins', 'Login failures (5 min)', $q('sum(platform_login_failures_5m)'), '', 'from app gauge', $range('sum(platform_login_failures_5m)'), 0),
            self::tile('workers', 'Workers alive', $q('sum(platform_workers_alive)'), '', 'heartbeat < 90 s', null, 0),
        ];
        $exporters = [
            'PostgreSQL' => [
                'Up' => self::fmt($q('min(pg_up)'), '', 0),
                'Backends' => self::fmt($q('sum(pg_stat_database_numbackends)'), '', 0),
                'Database size' => self::bytes($q('max(pg_database_size_bytes)')),
                'Commits / s' => self::fmt($q('sum(rate(pg_stat_database_xact_commit[5m]))'), '', 1),
                'Deadlocks (1 h)' => self::fmt($q('sum(increase(pg_stat_database_deadlocks[1h]))'), '', 0),
            ],
            'Redis' => [
                'Up' => self::fmt($q('min(redis_up)'), '', 0),
                'Memory used' => self::bytes($q('max(redis_memory_used_bytes)')),
                'Clients' => self::fmt($q('sum(redis_connected_clients)'), '', 0),
                'Ops / s' => self::fmt($q('sum(rate(redis_commands_processed_total[5m]))'), '', 0),
                'Evicted keys (1 h)' => self::fmt($q('sum(increase(redis_evicted_keys_total[1h]))'), '', 0),
            ],
            'Kafka' => [
                'Brokers' => self::fmt($q('max(kafka_brokers)'), '', 0),
                'Consumer lag' => self::fmt($q('sum(kafka_consumergroup_lag)'), '', 0),
                'Messages in / s' => self::fmt($q('sum(rate(kafka_topic_partition_current_offset[5m]))'), '', 1),
                'Under-replicated' => self::fmt($q('sum(kafka_topic_partition_under_replicated_partition)'), '', 0),
            ],
        ];
        $routes = [];
        try {
            foreach ($p->query('topk(10, sum by (route) (rate(platform_http_requests_total[15m])))') as $r) {
                $routes[] = ['route' => $r['metric']['route'] ?? '?', 'value' => self::fmt($r['value'], 'req/s', 3)];
            }
        } catch (ExternalServiceException) {
        }
        return ['source' => 'prometheus', 'tiles' => $tiles, 'exporters' => $exporters, 'routes' => $routes];
    }

    private function fromLocal(): array
    {
        $this->collector->collect();
        $families = [];
        try {
            foreach ($this->metrics->registry()->getMetricFamilySamples() as $f) {
                $families[$f->getName()] = $f;
            }
        } catch (Throwable) {
            $families = [];
        }
        $total = 0.0;
        $errors = 0.0;
        $byRoute = [];
        if (isset($families[self::NS . 'http_requests_total'])) {
            foreach ($families[self::NS . 'http_requests_total']->getSamples() as $s) {
                $labels = self::labels($s);
                $v = (float) $s->getValue();
                $total += $v;
                if (str_starts_with((string) ($labels['status'] ?? ''), '5')) {
                    $errors += $v;
                }
                $route = (string) ($labels['route'] ?? '?');
                $byRoute[$route] = ($byRoute[$route] ?? 0) + $v;
            }
        }
        arsort($byRoute);
        $buckets = [];
        if (isset($families[self::NS . 'http_request_duration_seconds'])) {
            foreach ($families[self::NS . 'http_request_duration_seconds']->getSamples() as $s) {
                if (!str_ends_with($s->getName(), '_bucket')) {
                    continue;
                }
                $labels = self::labels($s);
                $le = (string) ($labels['le'] ?? '+Inf');
                $buckets[$le] = ($buckets[$le] ?? 0) + (float) $s->getValue();
            }
        }
        $gauge = static function (string $name, array $match = []) use ($families): ?float {
            $f = $families[self::NS . $name] ?? null;
            if (!$f instanceof MetricFamilySamples) {
                return null;
            }
            $sum = 0.0;
            foreach ($f->getSamples() as $s) {
                $labels = self::labels($s);
                if (array_intersect_assoc($match, $labels) === $match) {
                    $sum += (float) $s->getValue();
                }
            }
            return $sum;
        };
        $hourly = fn(string $sql): array => array_map('floatval', $this->db->createCommand($sql)->queryColumn());
        $series = static fn(string $table, string $col, string $where = 'true'): string => "SELECT COALESCE(c, 0) FROM generate_series(date_trunc('hour', now()) - interval '23 hours', date_trunc('hour', now()), interval '1 hour') AS h
                LEFT JOIN (SELECT date_trunc('hour', $col) AS hh, count(*) AS c FROM $table WHERE $col > now() - interval '24 hours' AND $where GROUP BY 1) x ON x.hh = h ORDER BY h";

        $tiles = [
            self::tile('requests', 'HTTP requests', $total, '', 'cumulative since metrics reset', null, 0),
            self::tile('errors', 'Error rate (5xx)', $total > 0 ? $errors / $total * 100 : null, '%', 'cumulative share of 5xx', null, 2),
            self::tile('p95', 'Latency p95', self::quantile(0.95, $buckets), 'ms', 'estimated from histogram buckets', null, 0),
            self::tile('p99', 'Latency p99', self::quantile(0.99, $buckets), 'ms', 'estimated from histogram buckets', null, 0),
            self::tile('queue', 'Queue depth', $gauge('queue_jobs', ['status' => 'pending']), 'jobs', 'pending jobs (database)', $hourly($series('jobs', 'created_at')), 0),
            self::tile('outbox', 'Outbox pending', $gauge('outbox_events', ['status' => 'pending']), 'events', 'not yet published to Kafka', null, 0),
            self::tile('logins', 'Login failures (5 min)', $gauge('login_failures_5m'), '', 'sparkline: failures per hour, 24 h', $hourly($series('login_attempts', 'created_at', 'NOT success')), 0),
            self::tile('workers', 'Workers alive', $gauge('workers_alive'), '', 'heartbeat < 90 s', null, 0),
        ];
        $routes = [];
        foreach (array_slice($byRoute, 0, 10, true) as $route => $count) {
            $routes[] = ['route' => $route, 'value' => self::fmt($count, 'requests', 0)];
        }
        return ['source' => 'local', 'tiles' => $tiles, 'exporters' => [], 'routes' => $routes,
            'audit_series' => $hourly($series('audit_log', 'occurred_at'))];
    }

    /** Live gauges straight from PostgreSQL (shown in both modes). */
    private function dbGauges(): array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT (SELECT count(*) FROM jobs WHERE status = 'pending' AND available_at <= now()) AS runnable,
                   (SELECT COALESCE(EXTRACT(EPOCH FROM now() - min(available_at)), 0)::int FROM jobs WHERE status = 'pending' AND available_at <= now()) AS oldest_pending_s,
                   (SELECT count(*) FROM jobs WHERE status IN ('failed','dead') AND updated_at > now() - interval '1 hour') AS failed_1h,
                   (SELECT count(*) FROM outbox WHERE status = 'pending') AS outbox_pending,
                   (SELECT count(*) FROM kafka_dead_letters WHERE replayed_at IS NULL) AS dead_letters,
                   (SELECT count(*) FROM login_attempts WHERE NOT success AND created_at > now() - interval '1 hour') AS login_failures_1h,
                   (SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()) AS db_connections,
                   (SELECT count(*) FROM incidents WHERE status NOT IN ('resolved','closed')) AS incidents_open
            SQL)->queryOne();
        return array_map('intval', $row ?: []);
    }

    /** Label map of a sample (histogram bucket samples carry an extra "le" value). @return array<string,string> */
    private static function labels(\Prometheus\Sample $s): array
    {
        $names = $s->getLabelNames();
        $values = $s->getLabelValues();
        if (count($values) === count($names) + 1 && !in_array('le', $names, true)) {
            $names[] = 'le';
        }
        $n = min(count($names), count($values));
        return array_combine(array_slice($names, 0, $n), array_map('strval', array_slice($values, 0, $n)));
    }

    /** Prometheus-style histogram_quantile over cumulative buckets (seconds → milliseconds). */
    public static function quantile(float $q, array $buckets): ?float
    {
        if ($buckets === []) {
            return null;
        }
        $bounds = [];
        foreach ($buckets as $le => $count) {
            $bounds[] = [$le === '+Inf' ? INF : (float) $le, (float) $count];
        }
        usort($bounds, static fn($a, $b) => $a[0] <=> $b[0]);
        $total = end($bounds)[1];
        if ($total <= 0) {
            return null;
        }
        $rank = $q * $total;
        $prevBound = 0.0;
        $prevCount = 0.0;
        foreach ($bounds as [$bound, $count]) {
            if ($count >= $rank) {
                if (is_infinite($bound)) {
                    return $prevBound * 1000; // highest finite bucket, as Prometheus does
                }
                $inBucket = $count - $prevCount;
                $frac = $inBucket > 0 ? ($rank - $prevCount) / $inBucket : 0;
                return ($prevBound + ($bound - $prevBound) * $frac) * 1000;
            }
            [$prevBound, $prevCount] = [$bound, $count];
        }
        return $prevBound * 1000;
    }

    private static function tile(string $key, string $label, ?float $value, string $unit, string $hint, ?array $series, int $decimals): array
    {
        return ['key' => $key, 'label' => $label, 'value' => self::fmt($value, '', $decimals), 'raw' => $value, 'unit' => $unit, 'hint' => $hint, 'series' => $series];
    }

    private static function fmt(?float $v, string $unit, int $decimals): string
    {
        if ($v === null || is_nan($v)) {
            return '—';
        }
        if (is_infinite($v)) {
            return '∞';
        }
        return number_format($v, $decimals, '.', ' ') . ($unit !== '' ? ' ' . $unit : '');
    }

    private static function bytes(?float $v): string
    {
        if ($v === null || is_nan($v)) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($v >= 1024 && $i < 4) {
            $v /= 1024;
            $i++;
        }
        return sprintf($i === 0 ? '%d %s' : '%.1f %s', $v, $units[$i]);
    }
}
