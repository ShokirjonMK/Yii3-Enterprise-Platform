<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\User;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * 15 · Infrastructure: runtime inventory (worker processes from the `workers` heartbeat table,
 * PHP runtime/OPcache of the serving PHP-FPM pool) and Kubernetes operations.
 */
final class InfrastructureService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly OpsClients $clients,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array<string,mixed>> */
    public function workers(): array
    {
        return $this->db->createCommand(<<<'SQL'
            SELECT id, type, hostname, pid, queues, status, current_job_id, jobs_processed, jobs_failed, memory_bytes, started_at, heartbeat_at,
                   (heartbeat_at > now() - interval '90 seconds' AND status NOT IN ('stopped','lost')) AS alive,
                   EXTRACT(EPOCH FROM now() - heartbeat_at)::int AS heartbeat_age
            FROM workers
            WHERE heartbeat_at > now() - interval '1 day' OR status NOT IN ('stopped','lost')
            ORDER BY alive DESC, type, hostname, pid
            LIMIT 200
            SQL)->queryAll();
    }

    /** @return array<string,int> */
    public function workerSummary(): array
    {
        $out = [];
        foreach ($this->db->createCommand("SELECT type, count(*) FILTER (WHERE heartbeat_at > now() - interval '90 seconds' AND status NOT IN ('stopped','lost')) AS alive FROM workers GROUP BY type ORDER BY type")->queryAll() as $r) {
            $out[(string) $r['type']] = (int) $r['alive'];
        }
        return $out;
    }

    /** PHP runtime of the process serving this request (PHP-FPM pool in web context). */
    public function runtime(): array
    {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $op = null;
        if (is_array($opcache)) {
            $mem = $opcache['memory_usage'] ?? [];
            $stats = $opcache['opcache_statistics'] ?? [];
            $used = (float) ($mem['used_memory'] ?? 0);
            $free = (float) ($mem['free_memory'] ?? 0);
            $wasted = (float) ($mem['wasted_memory'] ?? 0);
            $op = [
                'enabled' => (bool) ($opcache['opcache_enabled'] ?? false),
                'used_memory' => (int) $used,
                'total_memory' => (int) ($used + $free + $wasted),
                'memory_pct' => ($used + $free + $wasted) > 0 ? round($used / ($used + $free + $wasted) * 100, 1) : 0.0,
                'wasted_pct' => round((float) ($mem['current_wasted_percentage'] ?? 0), 2),
                'cached_scripts' => (int) ($stats['num_cached_scripts'] ?? 0),
                'max_cached_keys' => (int) ($stats['max_cached_keys'] ?? 0),
                'hits' => (int) ($stats['hits'] ?? 0),
                'misses' => (int) ($stats['misses'] ?? 0),
                'hit_rate' => round((float) ($stats['opcache_hit_rate'] ?? 0), 2),
                'restarts' => (int) ($stats['oom_restarts'] ?? 0) + (int) ($stats['hash_restarts'] ?? 0) + (int) ($stats['manual_restarts'] ?? 0),
                'start_time' => isset($stats['start_time']) ? date(DATE_ATOM, (int) $stats['start_time']) : null,
                'jit' => (bool) (($opcache['jit']['enabled'] ?? false) && ($opcache['jit']['on'] ?? false)),
                'validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
            ];
        }
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_NATURAL | SORT_FLAG_CASE);
        return [
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'hostname' => (string) gethostname(),
            'pid' => getmypid(),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'realpath_cache_size' => (string) ini_get('realpath_cache_size'),
            'expose_php' => (bool) ini_get('expose_php'),
            'display_errors' => (string) ini_get('display_errors'),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'apcu' => function_exists('apcu_enabled') && apcu_enabled(),
            'extensions' => $extensions,
            'opcache' => $op,
            'load' => function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null,
        ];
    }

    /**
     * Kubernetes snapshot. Each list is fetched independently so that a missing RBAC grant on one
     * resource (typically cluster-scoped nodes) does not hide the others.
     *
     * @return array{configured:bool, namespace:string, api:string, version:?array, deployments:?array, pods:?array, nodes:?array, hpas:?array, errors:array<string,string>}
     */
    public function kubernetes(): array
    {
        $k8s = $this->clients->kubernetes();
        $out = ['configured' => $k8s->isConfigured(), 'namespace' => $k8s->namespace(), 'api' => $k8s->apiUrl(),
            'version' => null, 'deployments' => null, 'pods' => null, 'nodes' => null, 'hpas' => null, 'errors' => []];
        if (!$k8s->isConfigured()) {
            return $out;
        }
        foreach (['version', 'deployments', 'pods', 'nodes', 'hpas'] as $part) {
            try {
                $out[$part] = $k8s->{$part}();
            } catch (ExternalServiceException $e) {
                $out['errors'][$part] = $e->getMessage();
                if ($part === 'version' && $e->httpStatus === 0) {
                    break; // API unreachable — do not wait for every remaining timeout
                }
            }
        }
        return $out;
    }

    /** @return array{name:string, replicas:int, previous:int} */
    public function scale(User $actor, string $deployment, int $replicas, string $reason): array
    {
        $this->access->assert($actor->id, 'infra.manage');
        KubernetesClient::assertName($deployment);
        $reason = trim($reason);
        if ($reason === '') {
            throw \App\Shared\Domain\Exception\ValidationException::single('reason', 'A reason is required for scaling.');
        }
        $k8s = $this->clients->kubernetes();
        $meta = ['namespace' => $k8s->namespace(), 'reason' => mb_substr($reason, 0, 500)];
        try {
            $previous = $k8s->replicas($deployment);
            $result = $k8s->scale($deployment, $replicas);
        } catch (Throwable $e) {
            $this->audit->record('infra.k8s.scale', 'k8s.deployment', $deployment, null, ['replicas' => $replicas], $meta + ['error' => $e->getMessage()],
                result: 'failure', category: 'ops', severity: 'warning', actorId: $actor->id, actorName: $actor->username);
            throw $e;
        }
        $this->audit->record('infra.k8s.scale', 'k8s.deployment', $deployment, ['replicas' => $previous], ['replicas' => $result['replicas']], $meta,
            category: 'ops', severity: 'warning', actorId: $actor->id, actorName: $actor->username);
        return $result + ['previous' => $previous];
    }
}
