<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

use App\Platform\Incidents\IncidentService;
use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * `platform.health.probe` (scheduled every 5 minutes by PlatformSeeder):
 *  1. runs every {@see HealthChecker} check and exports platform_health_component_up /
 *     platform_health_check_latency_seconds metrics;
 *  2. keeps consecutive-failure counters per component in `health_probe_state`;
 *  3. opens an incident (fingerprint `health:<component>`) when a critical component is DOWN for
 *     {@see $failureThreshold} consecutive probes, and resolves it when the component is UP again.
 */
final class HealthProbeJob implements JobHandlerInterface
{
    /** @var array<string,string> critical component => incident severity */
    public const CRITICAL = ['postgres' => 'sev1', 'redis' => 'sev1', 'kafka' => 'sev2', 'storage' => 'sev2'];

    public function __construct(
        private readonly HealthChecker $health,
        private readonly Metrics $metrics,
        private readonly ConnectionInterface $db,
        private readonly IncidentService $incidents,
        private readonly LoggerInterface $logger,
        private readonly int $failureThreshold = 2,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->probe();
    }

    /**
     * @param array<string, array{status:string, latency_ms:float, details:array}>|null $results injected results (tests)
     * @return array{results:array, opened:list<string>, resolved:list<string>}
     */
    public function probe(?array $results = null): array
    {
        $results ??= $this->health->checkAll();
        $opened = [];
        $resolved = [];
        foreach ($results as $component => $r) {
            $up = match ($r['status']) { HealthChecker::UP => 1.0, HealthChecker::DEGRADED => 0.5, HealthChecker::NOT_CONFIGURED => -1.0, default => 0.0 };
            $this->metrics->gauge('health_component_up', 'Health probe result per component (1 up, 0.5 degraded, 0 down, -1 not configured)', $up, ['component' => $component]);
            $this->metrics->observe('health_check_latency_seconds', 'Health check latency', $r['latency_ms'] / 1000, ['component' => $component], [0.005, 0.01, 0.05, 0.1, 0.5, 1, 3, 5]);

            $failures = $this->record($component, $r);
            if (!isset(self::CRITICAL[$component])) {
                continue;
            }
            $fingerprint = 'health:' . $component;
            try {
                if ($r['status'] === HealthChecker::DOWN && $failures >= $this->failureThreshold) {
                    $error = (string) ($r['details']['error'] ?? 'no details');
                    $result = $this->incidents->autoOpen($fingerprint, ucfirst($component) . ' is down', self::CRITICAL[$component], $component,
                        sprintf("Health probe reported %s DOWN for %d consecutive checks.\nLast error: %s", $component, $failures, mb_substr($error, 0, 1000)));
                    if ($result['created']) {
                        $opened[] = $result['id'];
                    }
                } elseif ($r['status'] === HealthChecker::UP) {
                    $id = $this->incidents->autoResolve($fingerprint, sprintf('Health probe: %s is up again (%.1f ms).', $component, $r['latency_ms']));
                    if ($id !== null) {
                        $resolved[] = $id;
                    }
                }
            } catch (Throwable $e) {
                // When PostgreSQL itself is down the incident cannot be stored; alerting relies on Prometheus/Alertmanager then
                $this->logger->error('Health probe could not update incidents', ['component' => $component, 'exception' => $e]);
            }
        }
        return ['results' => $results, 'opened' => $opened, 'resolved' => $resolved];
    }

    /** Upserts the probe state and returns the number of consecutive DOWN results. */
    private function record(string $component, array $r): int
    {
        try {
            return (int) $this->db->createCommand(<<<'SQL'
                INSERT INTO health_probe_state AS s (component, status, consecutive_failures, details, last_checked_at, last_change_at)
                VALUES (:c, :s, :f, CAST(:d AS jsonb), now(), now())
                ON CONFLICT (component) DO UPDATE SET
                    consecutive_failures = CASE WHEN EXCLUDED.status = 'down' THEN s.consecutive_failures + 1 ELSE 0 END,
                    last_change_at = CASE WHEN s.status <> EXCLUDED.status THEN now() ELSE s.last_change_at END,
                    status = EXCLUDED.status, details = EXCLUDED.details, last_checked_at = now()
                RETURNING consecutive_failures
                SQL, [':c' => $component, ':s' => $r['status'], ':f' => $r['status'] === HealthChecker::DOWN ? 1 : 0, ':d' => json_encode(\App\Shared\Infrastructure\Logging\Redactor::redact($r['details']), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)])->queryScalar();
        } catch (Throwable $e) {
            $this->logger->warning('Health probe state not stored', ['component' => $component, 'exception' => $e->getMessage()]);
            return 0;
        }
    }
}
