<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure;

use App\Shared\Domain\Exception\ValidationException;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * Kubernetes REST API adapter (no kubectl, no client library): bearer service-account token,
 * TLS verification against the cluster CA. Read operations list workloads of one namespace;
 * the only write operation is scaling a Deployment through its /scale subresource.
 *
 * Required RBAC for the service account (namespace-scoped Role + optional ClusterRole for nodes):
 *   apps/deployments, apps/deployments/scale: get, list, patch
 *   pods, autoscaling/horizontalpodautoscalers: get, list
 *   nodes (cluster scope): list   — optional; the UI reports a permission error otherwise
 */
final class KubernetesClient
{
    public const DNS1123 = '/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/';
    public const MAX_REPLICAS = 100;

    private ?JsonHttp $http = null;

    /** @param bool|string $verify true | CA bundle path | false (never in production) */
    public function __construct(
        private readonly string $apiUrl,
        #[SensitiveParameter]
        private readonly ?string $token,
        private readonly string $namespace = 'platform',
        private readonly bool|string $verify = true,
        private readonly mixed $handler = null,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->apiUrl) !== '' && $this->token !== null && $this->token !== '';
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function apiUrl(): string
    {
        return $this->apiUrl;
    }

    /** @return array{major:string, minor:string, gitVersion:string, platform:string} */
    public function version(): array
    {
        $v = $this->http()->request('GET', '/version');
        return [
            'major' => (string) ($v['major'] ?? ''),
            'minor' => (string) ($v['minor'] ?? ''),
            'gitVersion' => (string) ($v['gitVersion'] ?? ''),
            'platform' => (string) ($v['platform'] ?? ''),
        ];
    }

    /** @return list<array{name:string, replicas:int, ready:int, available:int, updated:int, images:list<string>, age:?string, conditions:list<string>}> */
    public function deployments(): array
    {
        $list = $this->http()->request('GET', '/apis/apps/v1/namespaces/' . rawurlencode($this->namespace) . '/deployments');
        $out = [];
        foreach ($list['items'] ?? [] as $d) {
            $conditions = [];
            foreach ($d['status']['conditions'] ?? [] as $c) {
                if (($c['status'] ?? '') !== 'True') {
                    $conditions[] = (string) ($c['type'] ?? '') . ': ' . (string) ($c['reason'] ?? '');
                }
            }
            $out[] = [
                'name' => (string) ($d['metadata']['name'] ?? ''),
                'replicas' => (int) ($d['spec']['replicas'] ?? 0),
                'ready' => (int) ($d['status']['readyReplicas'] ?? 0),
                'available' => (int) ($d['status']['availableReplicas'] ?? 0),
                'updated' => (int) ($d['status']['updatedReplicas'] ?? 0),
                'images' => array_values(array_map(static fn($c) => (string) ($c['image'] ?? ''), $d['spec']['template']['spec']['containers'] ?? [])),
                'age' => self::age($d['metadata']['creationTimestamp'] ?? null),
                'conditions' => $conditions,
            ];
        }
        return $out;
    }

    /** @return list<array{name:string, phase:string, ready:string, restarts:int, node:string, ip:string, age:?string}> */
    public function pods(): array
    {
        $list = $this->http()->request('GET', '/api/v1/namespaces/' . rawurlencode($this->namespace) . '/pods');
        $out = [];
        foreach ($list['items'] ?? [] as $p) {
            $statuses = $p['status']['containerStatuses'] ?? [];
            $ready = count(array_filter($statuses, static fn($s) => ($s['ready'] ?? false) === true));
            $restarts = array_sum(array_map(static fn($s) => (int) ($s['restartCount'] ?? 0), $statuses));
            $phase = (string) ($p['status']['phase'] ?? 'Unknown');
            foreach ($statuses as $s) {
                // Surface CrashLoopBackOff / ImagePullBackOff instead of the generic phase
                if (isset($s['state']['waiting']['reason'])) {
                    $phase = (string) $s['state']['waiting']['reason'];
                }
            }
            $out[] = [
                'name' => (string) ($p['metadata']['name'] ?? ''),
                'phase' => $phase,
                'ready' => $ready . '/' . count($p['spec']['containers'] ?? $statuses),
                'restarts' => $restarts,
                'node' => (string) ($p['spec']['nodeName'] ?? ''),
                'ip' => (string) ($p['status']['podIP'] ?? ''),
                'age' => self::age($p['metadata']['creationTimestamp'] ?? null),
            ];
        }
        return $out;
    }

    /** @return list<array{name:string, ready:bool, roles:list<string>, version:string, cpu:string, memory:string, ip:string, unschedulable:bool}> */
    public function nodes(): array
    {
        $list = $this->http()->request('GET', '/api/v1/nodes');
        $out = [];
        foreach ($list['items'] ?? [] as $n) {
            $ready = false;
            foreach ($n['status']['conditions'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'Ready') {
                    $ready = ($c['status'] ?? '') === 'True';
                }
            }
            $roles = [];
            foreach (array_keys($n['metadata']['labels'] ?? []) as $label) {
                if (str_starts_with((string) $label, 'node-role.kubernetes.io/')) {
                    $roles[] = substr((string) $label, strlen('node-role.kubernetes.io/'));
                }
            }
            $ip = '';
            foreach ($n['status']['addresses'] ?? [] as $a) {
                if (($a['type'] ?? '') === 'InternalIP') {
                    $ip = (string) $a['address'];
                }
            }
            $out[] = [
                'name' => (string) ($n['metadata']['name'] ?? ''),
                'ready' => $ready,
                'roles' => $roles,
                'version' => (string) ($n['status']['nodeInfo']['kubeletVersion'] ?? ''),
                'cpu' => (string) ($n['status']['allocatable']['cpu'] ?? ''),
                'memory' => (string) ($n['status']['allocatable']['memory'] ?? ''),
                'ip' => $ip,
                'unschedulable' => (bool) ($n['spec']['unschedulable'] ?? false),
            ];
        }
        return $out;
    }

    /** @return list<array{name:string, target:string, min:int, max:int, current:int, desired:int, metrics:list<string>}> */
    public function hpas(): array
    {
        $list = $this->http()->request('GET', '/apis/autoscaling/v2/namespaces/' . rawurlencode($this->namespace) . '/horizontalpodautoscalers');
        $out = [];
        foreach ($list['items'] ?? [] as $h) {
            $metrics = [];
            foreach ($h['status']['currentMetrics'] ?? [] as $i => $m) {
                $name = $m['resource']['name'] ?? ($m['pods']['metric']['name'] ?? ($m['type'] ?? 'metric'));
                $current = $m['resource']['current']['averageUtilization'] ?? null;
                $target = $h['spec']['metrics'][$i]['resource']['target']['averageUtilization'] ?? null;
                $metrics[] = $name . ': ' . ($current === null ? '?' : $current . '%') . ($target === null ? '' : ' / ' . $target . '%');
            }
            $out[] = [
                'name' => (string) ($h['metadata']['name'] ?? ''),
                'target' => (string) (($h['spec']['scaleTargetRef']['kind'] ?? '') . '/' . ($h['spec']['scaleTargetRef']['name'] ?? '')),
                'min' => (int) ($h['spec']['minReplicas'] ?? 1),
                'max' => (int) ($h['spec']['maxReplicas'] ?? 0),
                'current' => (int) ($h['status']['currentReplicas'] ?? 0),
                'desired' => (int) ($h['status']['desiredReplicas'] ?? 0),
                'metrics' => $metrics,
            ];
        }
        return $out;
    }

    /** Current spec.replicas of a Deployment (scale subresource). */
    public function replicas(string $deployment): int
    {
        self::assertName($deployment);
        $scale = $this->http()->request('GET', $this->scalePath($deployment));
        return (int) ($scale['spec']['replicas'] ?? 0);
    }

    /**
     * Scales a Deployment (JSON merge patch on the scale subresource).
     *
     * @return array{name:string, replicas:int}
     */
    public function scale(string $deployment, int $replicas): array
    {
        self::assertName($deployment);
        if ($replicas < 0 || $replicas > self::MAX_REPLICAS) {
            throw ValidationException::single('replicas', 'Replicas must be between 0 and ' . self::MAX_REPLICAS . '.');
        }
        $result = $this->http()->request('PATCH', $this->scalePath($deployment), [
            'body' => (string) json_encode(['spec' => ['replicas' => $replicas]]),
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        return [
            'name' => (string) ($result['metadata']['name'] ?? $deployment),
            'replicas' => (int) ($result['spec']['replicas'] ?? $replicas),
        ];
    }

    public static function assertName(string $name): void
    {
        if (strlen($name) > 253 || preg_match(self::DNS1123, $name) !== 1) {
            throw ValidationException::single('deployment', 'Invalid Kubernetes object name.');
        }
    }

    private function scalePath(string $deployment): string
    {
        return '/apis/apps/v1/namespaces/' . rawurlencode($this->namespace) . '/deployments/' . rawurlencode($deployment) . '/scale';
    }

    private function http(): JsonHttp
    {
        if (!$this->isConfigured()) {
            throw new ExternalServiceException('Kubernetes', 'Kubernetes is not configured (ops.kubernetes_api / ops.kubernetes_token).');
        }
        return $this->http ??= new JsonHttp('Kubernetes', $this->apiUrl, ['Authorization' => 'Bearer ' . $this->token], $this->verify, 5.0, $this->handler);
    }

    private static function age(mixed $timestamp): ?string
    {
        if (!is_string($timestamp) || $timestamp === '') {
            return null;
        }
        try {
            $s = time() - (new DateTimeImmutable($timestamp))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
        return match (true) {
            $s < 3600 => intdiv(max(0, $s), 60) . 'm',
            $s < 86400 => intdiv($s, 3600) . 'h',
            default => intdiv($s, 86400) . 'd',
        };
    }
}
