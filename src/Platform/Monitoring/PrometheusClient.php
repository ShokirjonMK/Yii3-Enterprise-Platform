<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

use App\Platform\Infrastructure\ExternalServiceException;
use App\Platform\Infrastructure\JsonHttp;

/**
 * Prometheus HTTP API v1 client (instant + range queries, readiness). Used by the Monitoring
 * section; when Prometheus is unreachable the section falls back to the in-app registry.
 */
final class PrometheusClient
{
    private ?JsonHttp $http = null;
    private ?bool $reachable = null;

    public function __construct(
        private readonly ?string $baseUrl,
        private readonly mixed $handler = null,
        private readonly float $timeout = 3.0,
    ) {}

    public function isConfigured(): bool
    {
        return $this->baseUrl !== null && trim($this->baseUrl) !== '';
    }

    public function baseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /** Fast reachability probe (cached per instance). */
    public function isReachable(): bool
    {
        if ($this->reachable !== null) {
            return $this->reachable;
        }
        if (!$this->isConfigured()) {
            return $this->reachable = false;
        }
        try {
            $r = $this->http()->request('GET', '/api/v1/status/buildinfo');
            return $this->reachable = ($r['status'] ?? '') === 'success';
        } catch (ExternalServiceException) {
            return $this->reachable = false;
        }
    }

    /** @return list<array{metric:array<string,string>, value:float}> */
    public function query(string $promql): array
    {
        $data = $this->data($this->http()->request('GET', '/api/v1/query', ['query' => ['query' => $promql]]));
        $out = [];
        if (($data['resultType'] ?? '') === 'scalar') {
            return [['metric' => [], 'value' => self::num($data['result'][1] ?? null)]];
        }
        foreach ($data['result'] ?? [] as $r) {
            $out[] = ['metric' => (array) ($r['metric'] ?? []), 'value' => self::num($r['value'][1] ?? null)];
        }
        return $out;
    }

    /** First sample of an instant query, null when the result is empty or NaN. */
    public function scalar(string $promql): ?float
    {
        $r = $this->query($promql);
        if ($r === [] || is_nan($r[0]['value'])) {
            return null;
        }
        return $r[0]['value'];
    }

    /** @return list<array{metric:array<string,string>, values:list<array{0:int,1:float}>}> */
    public function queryRange(string $promql, int $start, int $end, int $step): array
    {
        $data = $this->data($this->http()->request('GET', '/api/v1/query_range', ['query' => [
            'query' => $promql, 'start' => $start, 'end' => $end, 'step' => max(1, $step),
        ]]));
        $out = [];
        foreach ($data['result'] ?? [] as $r) {
            $values = [];
            foreach ($r['values'] ?? [] as $v) {
                $values[] = [(int) $v[0], self::num($v[1] ?? null)];
            }
            $out[] = ['metric' => (array) ($r['metric'] ?? []), 'values' => $values];
        }
        return $out;
    }

    private function data(array $response): array
    {
        if (($response['status'] ?? '') !== 'success') {
            throw new ExternalServiceException('Prometheus', 'Prometheus query failed: ' . (string) ($response['error'] ?? 'unknown error'));
        }
        return (array) ($response['data'] ?? []);
    }

    private static function num(mixed $v): float
    {
        if ($v === null) {
            return NAN;
        }
        return match ((string) $v) {
            'NaN' => NAN,
            '+Inf' => INF,
            '-Inf' => -INF,
            default => (float) $v,
        };
    }

    private function http(): JsonHttp
    {
        if (!$this->isConfigured()) {
            throw new ExternalServiceException('Prometheus', 'PROMETHEUS_URL is not configured.');
        }
        return $this->http ??= new JsonHttp('Prometheus', (string) $this->baseUrl, [], true, $this->timeout, $this->handler);
    }
}
