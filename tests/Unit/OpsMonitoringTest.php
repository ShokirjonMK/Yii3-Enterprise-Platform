<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Action\Audit\AuditSearch;
use App\Platform\Monitoring\AlertmanagerClient;
use App\Platform\Monitoring\AsvsChecklist;
use App\Platform\Monitoring\ObservabilityService;
use App\Platform\Monitoring\PrometheusClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class OpsMonitoringTest extends TestCase
{
    private static function json(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public function testPrometheusQueries(): void
    {
        $mock = new MockHandler([
            self::json(['status' => 'success', 'data' => ['version' => '3.0']]),
            self::json(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => ['route' => 'admin.dashboard'], 'value' => [1730700000, '12.5']]]]]),
            self::json(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1730700000, 'NaN']]]]]),
            self::json(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [['metric' => [], 'values' => [[1, '1'], [61, '2.5']]]]]]),
        ]);
        $p = new PrometheusClient('http://prometheus:9090', $mock);
        self::assertTrue($p->isReachable());
        self::assertSame(12.5, $p->query('sum(rate(x[5m]))')[0]['value']);
        self::assertNull($p->scalar('histogram_quantile(0.95, x)'));
        self::assertSame([[1, 1.0], [61, 2.5]], $p->queryRange('x', 0, 60, 60)[0]['values']);
        self::assertSame('/api/v1/query_range', $mock->getLastRequest()?->getUri()->getPath());
    }

    public function testPrometheusUnreachableAndUnconfigured(): void
    {
        $p = new PrometheusClient('http://prometheus:9090', new MockHandler([new ConnectException('Connection refused', new Request('GET', '/'))]));
        self::assertFalse($p->isReachable());
        self::assertFalse((new PrometheusClient(null))->isReachable());
    }

    public function testHistogramQuantile(): void
    {
        // 100 requests: 50 ≤ 0.1 s, 90 ≤ 0.25 s, 100 ≤ 0.5 s
        $buckets = ['0.1' => 50, '0.25' => 90, '0.5' => 100, '+Inf' => 100];
        self::assertEqualsWithDelta(100.0, ObservabilityService::quantile(0.5, $buckets), 0.001);
        self::assertEqualsWithDelta(375.0, ObservabilityService::quantile(0.95, $buckets), 0.001);
        self::assertNull(ObservabilityService::quantile(0.95, []));
        self::assertEqualsWithDelta(500.0, ObservabilityService::quantile(0.99, ['0.5' => 10, '+Inf' => 20]), 0.001);
    }

    public function testAlertmanagerActiveAlertsSortedBySeverity(): void
    {
        $am = new AlertmanagerClient('http://alertmanager:9093', new MockHandler([self::json([
            ['labels' => ['alertname' => 'QueueBacklog', 'severity' => 'warning'], 'annotations' => ['summary' => 'Backlog'], 'status' => ['state' => 'active'], 'startsAt' => '2026-11-04T08:00:00Z', 'fingerprint' => 'a'],
            ['labels' => ['alertname' => 'PostgresDown', 'severity' => 'critical'], 'annotations' => ['summary' => 'PG down'], 'status' => ['state' => 'active'], 'fingerprint' => 'b'],
        ])]));
        $alerts = $am->activeAlerts();
        self::assertSame(['PostgresDown', 'QueueBacklog'], array_column($alerts, 'name'));
    }

    public function testAsvsChecklistParser(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'asvs');
        file_put_contents($file, "# ASVS\n\n## V2 Authentication\n\n| ID | Requirement | Status | Evidence |\n|---|---|---|---|\n| 2.1.1 | Passwords ≥ 12 chars | **Implemented** | `PasswordPolicy` |\n| 2.2.1 | Anti-automation | partial | LoginThrottle |\n\n## V5 Validation\n| 5.1.1 | HTTP parameter pollution | N/A | — |\n");
        $r = (new AsvsChecklist($file))->load();
        unlink($file);
        self::assertTrue($r['available']);
        self::assertCount(2, $r['chapters']);
        self::assertSame('implemented', $r['chapters'][0]['items'][0]['status']);
        self::assertSame('PasswordPolicy', $r['chapters'][0]['items'][0]['evidence']);
        self::assertSame(['implemented' => 1, 'partial' => 1, 'n/a' => 1, 'planned' => 0], $r['totals']);
    }

    public function testRepositoryAsvsDocumentParses(): void
    {
        $r = AsvsChecklist::default()->load();
        self::assertTrue($r['available'], 'docs/SECURITY_ASVS.md exists');
        self::assertGreaterThanOrEqual(10, count($r['chapters']));
        self::assertGreaterThan(50, array_sum($r['totals']));
    }

    public function testAuditDiffAndCsvRedaction(): void
    {
        $diff = AuditSearch::diff(['status' => 'active', 'name' => 'A', 'meta' => ['x' => 1]], ['status' => 'blocked', 'name' => 'A', 'meta' => ['y' => 2]]);
        $byPath = array_column($diff, 'change', 'path');
        self::assertSame('changed', $byPath['status']);
        self::assertSame('same', $byPath['name']);
        self::assertSame('removed', $byPath['meta.x']);
        self::assertSame('added', $byPath['meta.y']);

        $row = AuditSearch::csvRow([
            'id' => 1, 'occurred_at' => 't', 'category' => 'data', 'action' => '=HYPERLINK("http://evil")', 'result' => 'success', 'severity' => 'info',
            'actor_id' => null, 'actor' => 'x', 'actor_type' => 'user', 'tenant_id' => null, 'entity_type' => 'e', 'entity_id' => '1', 'ip' => '1.2.3.4',
            'user_agent' => 'curl', 'correlation_id' => 'c', 'before' => '{"password":"p@ss"}', 'after' => null, 'metadata' => '{"note":"Bearer abcdef123"}',
        ]);
        self::assertStringStartsWith("'=", $row[3], 'CSV formula injection neutralised');
        self::assertStringNotContainsString('p@ss', $row[15]);
        self::assertStringNotContainsString('abcdef123', $row[17]);
    }
}
