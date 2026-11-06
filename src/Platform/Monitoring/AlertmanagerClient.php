<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

use App\Platform\Infrastructure\ExternalServiceException;
use App\Platform\Infrastructure\JsonHttp;

/**
 * Alertmanager API v2 client: active (not silenced / not inhibited) alerts.
 */
final class AlertmanagerClient
{
    private ?JsonHttp $http = null;

    public function __construct(
        private readonly ?string $baseUrl,
        private readonly mixed $handler = null,
        private readonly float $timeout = 2.0,
    ) {}

    public function isConfigured(): bool
    {
        return $this->baseUrl !== null && trim($this->baseUrl) !== '';
    }

    public function baseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /** @return list<array{name:string, severity:string, state:string, summary:string, starts_at:?string, labels:array<string,string>, fingerprint:string}> */
    public function activeAlerts(): array
    {
        if (!$this->isConfigured()) {
            throw new ExternalServiceException('Alertmanager', 'ALERTMANAGER_URL is not configured.');
        }
        $this->http ??= new JsonHttp('Alertmanager', (string) $this->baseUrl, [], true, $this->timeout, $this->handler);
        $rows = $this->http->request('GET', '/api/v2/alerts', ['query' => ['active' => 'true', 'silenced' => 'false', 'inhibited' => 'false']]);
        $out = [];
        foreach ($rows as $a) {
            if (!is_array($a)) {
                continue;
            }
            $labels = array_map('strval', (array) ($a['labels'] ?? []));
            $out[] = [
                'name' => $labels['alertname'] ?? 'alert',
                'severity' => $labels['severity'] ?? 'none',
                'state' => (string) ($a['status']['state'] ?? 'active'),
                'summary' => (string) ($a['annotations']['summary'] ?? $a['annotations']['description'] ?? ''),
                'starts_at' => isset($a['startsAt']) ? (string) $a['startsAt'] : null,
                'labels' => $labels,
                'fingerprint' => (string) ($a['fingerprint'] ?? ''),
            ];
        }
        usort($out, static fn($x, $y) => [self::rank($x['severity']), $x['name']] <=> [self::rank($y['severity']), $y['name']]);
        return $out;
    }

    private static function rank(string $severity): int
    {
        return match ($severity) {
            'critical' => 0, 'error', 'high' => 1, 'warning' => 2, default => 3
        };
    }
}
