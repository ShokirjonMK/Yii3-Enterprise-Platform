<?php

declare(strict_types=1);

namespace App\Platform\Redis;

use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Cache\FailSafeCache;
use App\Shared\Infrastructure\Logging\Redactor;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Predis\Client;
use Predis\ClientInterface;
use Predis\Command\RawCommand;
use Throwable;

/**
 * Redis diagnostics for the Control Center (13 · Redis Management).
 *
 * Safety rules enforced here (not only in the UI):
 *  - keys are enumerated with SCAN (never KEYS), only inside the application prefix,
 *  - values of sensitive keys (sessions, locks, rate limits, tokens, secrets) are never returned — size only,
 *  - the only destructive operation is clearing the cache namespace `<prefix>cache:` (optionally a sub-prefix)
 *    with SCAN + UNLINK; FLUSHALL/FLUSHDB are never used (and FLUSHALL is renamed off in redis.conf).
 */
final class RedisInspector
{
    /** Sub-prefixes owned by the platform (relative to the application prefix). */
    public const NAMESPACES = [
        'cache:' => 'Application cache (PSR-16, fail-open)',
        'sess:' => 'PHP sessions (fail-closed)',
        'lock:' => 'Distributed locks',
        'rl:' => 'Rate-limit counters',
        'metrics:' => 'Prometheus metrics',
    ];

    private const SENSITIVE_PREFIXES = ['sess:', 'lock:', 'rl:'];
    private const SENSITIVE_WORDS = '/(sess|token|secret|passw|csrf|mfa|otp|auth|cookie|key_material|credential)/i';
    /** No glob metacharacters (* ? [ ] \); "," appears in PHP session ids. */
    private const SAFE_FRAGMENT = '/^[A-Za-z0-9:_.,\-]{0,160}$/';

    private ?ClientInterface $client = null;

    /**
     * @param array{mode:string,host:string,port:int,password:?string,database:int,sentinels:list<string>,sentinelService:string,prefix:string,timeout:float} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly AuditLogger $audit,
        ?ClientInterface $client = null,
        private readonly ?array $cacheConfig = null,
    ) {
        $this->client = $client;
    }

    /** Is the cache a separate Redis instance (REDIS_CACHE_HOST / REDIS_CACHE_URL)? */
    public function cacheSeparate(): bool
    {
        return (bool) ($this->cacheConfig['separate'] ?? false);
    }

    /**
     * Inspector of one role: "state" (sessions, locks, rate limits — this instance) or "cache". When the cache shares
     * the state instance both names return the same inspector.
     */
    public function instance(string $role): self
    {
        if ($role === 'cache' && $this->cacheSeparate()) {
            return new self($this->cacheConfig, $this->audit, null, null);
        }
        return $this;
    }

    public function role(): string
    {
        return (string) ($this->config['role'] ?? 'state');
    }

    public function prefix(): string
    {
        return (string) $this->config['prefix'];
    }

    public function cachePrefix(): string
    {
        return $this->prefix() . 'cache:';
    }

    /** @return array{mode:string, endpoints:list<string>, database:int, prefix:string, service:?string} (no credentials) */
    public function topology(): array
    {
        $mode = (string) $this->config['mode'];
        return [
            'mode' => $mode,
            'endpoints' => $mode === 'standalone' ? [$this->config['host'] . ':' . $this->config['port']] : array_values($this->config['sentinels'] ?: [$this->config['host'] . ':' . $this->config['port']]),
            'database' => (int) $this->config['database'],
            'prefix' => $this->prefix(),
            'service' => $mode === 'sentinel' ? (string) $this->config['sentinelService'] : null,
            'auth' => ($this->config['password'] ?? null) !== null && $this->config['password'] !== '',
            'acl_user' => ($this->config['username'] ?? null) ?: null,
            'tls' => (bool) ($this->config['tls'] ?? false),
            'persistent' => (bool) ($this->config['persistent'] ?? false),
            'role' => $this->role(),
        ];
    }

    public function isCacheDegraded(): bool
    {
        return FailSafeCache::isDegraded();
    }

    /** @return array{ok:bool, latency_ms:float, error:?string} */
    public function ping(): array
    {
        $t = microtime(true);
        try {
            $pong = (string) $this->raw(['PING']);
            return ['ok' => str_contains(strtoupper($pong), 'PONG'), 'latency_ms' => round((microtime(true) - $t) * 1000, 2), 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'latency_ms' => round((microtime(true) - $t) * 1000, 2), 'error' => Redactor::redactString($e->getMessage())];
        }
    }

    /**
     * INFO parsed into sections + derived values (hit rate, eviction policy, persistence & replication state).
     *
     * @return array{sections: array<string, array<string,string>>, derived: array<string,mixed>}
     */
    public function info(): array
    {
        $sections = self::parseInfo((string) $this->raw(['INFO', 'everything']));
        $stats = $sections['Stats'] ?? [];
        $hits = (int) ($stats['keyspace_hits'] ?? 0);
        $misses = (int) ($stats['keyspace_misses'] ?? 0);
        $mem = $sections['Memory'] ?? [];
        $keyspace = [];
        foreach ($sections['Keyspace'] ?? [] as $db => $line) {
            parse_str(str_replace(',', '&', $line), $kv);
            $keyspace[$db] = array_map('intval', $kv);
        }
        // Remove anything that could disclose secrets or host internals irrelevant for operators
        unset($sections['Server']['executable'], $sections['Server']['config_file']);
        return [
            'sections' => $sections,
            'derived' => [
                'version' => $sections['Server']['redis_version'] ?? null,
                'uptime_seconds' => (int) ($sections['Server']['uptime_in_seconds'] ?? 0),
                'role' => $sections['Replication']['role'] ?? null,
                'connected_clients' => (int) ($sections['Clients']['connected_clients'] ?? 0),
                'blocked_clients' => (int) ($sections['Clients']['blocked_clients'] ?? 0),
                'used_memory' => (int) ($mem['used_memory'] ?? 0),
                'used_memory_peak' => (int) ($mem['used_memory_peak'] ?? 0),
                'maxmemory' => (int) ($mem['maxmemory'] ?? 0),
                'maxmemory_policy' => $mem['maxmemory_policy'] ?? null,
                'fragmentation_ratio' => isset($mem['mem_fragmentation_ratio']) ? (float) $mem['mem_fragmentation_ratio'] : null,
                'hits' => $hits,
                'misses' => $misses,
                'hit_rate' => $hits + $misses > 0 ? round($hits / ($hits + $misses) * 100, 2) : null,
                'evicted_keys' => (int) ($stats['evicted_keys'] ?? 0),
                'expired_keys' => (int) ($stats['expired_keys'] ?? 0),
                'ops_per_sec' => (int) ($stats['instantaneous_ops_per_sec'] ?? 0),
                'rejected_connections' => (int) ($stats['rejected_connections'] ?? 0),
                'aof_enabled' => ($sections['Persistence']['aof_enabled'] ?? '0') === '1',
                'rdb_last_save' => isset($sections['Persistence']['rdb_last_save_time']) ? (int) $sections['Persistence']['rdb_last_save_time'] : null,
                'rdb_last_status' => $sections['Persistence']['rdb_last_bgsave_status'] ?? null,
                'aof_last_status' => $sections['Persistence']['aof_last_write_status'] ?? null,
                'connected_slaves' => (int) ($sections['Replication']['connected_slaves'] ?? 0),
                'keyspace' => $keyspace,
            ],
        ];
    }

    /** @return array<string, array<string,string>> */
    public static function parseInfo(string $raw): array
    {
        $sections = [];
        $current = 'General';
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                $current = trim(substr($line, 1));
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $sections[$current][substr($line, 0, $pos)] = substr($line, $pos + 1);
        }
        return $sections;
    }

    /**
     * SLOWLOG GET — arguments beyond the command name and first key are masked (they may contain values).
     *
     * @return list<array{id:int, at:int, duration_us:int, command:string, client:?string, client_name:?string}>
     */
    public function slowlog(int $count = 25): array
    {
        $out = [];
        foreach ((array) $this->raw(['SLOWLOG', 'GET', (string) $count]) as $e) {
            $args = array_map('strval', (array) ($e[3] ?? []));
            $name = strtoupper($args[0] ?? '?');
            $shown = [$name];
            if (isset($args[1])) {
                $shown[] = $this->isSensitiveKey($this->relative($args[1])) ? '[sensitive key]' : mb_strimwidth($args[1], 0, 120, '…');
            }
            if (count($args) > 2) {
                $shown[] = '… (' . (count($args) - 2) . ' more args)';
            }
            $out[] = [
                'id' => (int) ($e[0] ?? 0),
                'at' => (int) ($e[1] ?? 0),
                'duration_us' => (int) ($e[2] ?? 0),
                'command' => implode(' ', $shown),
                'client' => isset($e[4]) ? (string) $e[4] : null,
                'client_name' => isset($e[5]) && $e[5] !== '' ? (string) $e[5] : null,
            ];
        }
        return $out;
    }

    /** @return list<array{event:string, at:int, latest_ms:int, max_ms:int}> */
    public function latency(): array
    {
        try {
            $rows = (array) $this->raw(['LATENCY', 'LATEST']);
        } catch (Throwable) {
            return [];
        }
        return array_map(static fn($r) => [
            'event' => (string) ($r[0] ?? '?'), 'at' => (int) ($r[1] ?? 0), 'latest_ms' => (int) ($r[2] ?? 0), 'max_ms' => (int) ($r[3] ?? 0),
        ], $rows);
    }

    /**
     * Key browser (SCAN, never KEYS). $subPrefix is relative to the application prefix.
     *
     * @return array{keys: list<array{key:string, type:string, ttl:int, size:?int, namespace:string, sensitive:bool}>, cursor:string, scanned_batches:int}
     */
    public function scan(string $subPrefix = '', string $cursor = '0', int $limit = 100): array
    {
        $this->assertFragment($subPrefix);
        if (!preg_match('/^\d{1,20}$/', $cursor)) {
            $cursor = '0';
        }
        $limit = max(1, min(500, $limit));
        $pattern = $this->prefix() . $subPrefix . '*';
        $found = [];
        $batches = 0;
        do {
            [$cursor, $keys] = (array) $this->raw(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '500']);
            $cursor = (string) $cursor;
            foreach ((array) $keys as $k) {
                $found[(string) $k] = true;
            }
            $batches++;
        } while ($cursor !== '0' && count($found) < $limit && $batches < 200);

        $rows = [];
        foreach (array_slice(array_keys($found), 0, $limit) as $full) {
            $rows[] = $this->describe($full);
        }
        usort($rows, static fn($a, $b) => strcmp($a['key'], $b['key']));
        return ['keys' => $rows, 'cursor' => $cursor, 'scanned_batches' => $batches];
    }

    /**
     * Metadata of a single key (relative name). Value preview only for non-sensitive string keys,
     * redacted and truncated; never for sessions, locks, rate limits or secret-looking names.
     *
     * @return array<string,mixed>|null
     */
    public function inspect(string $relativeKey): ?array
    {
        $this->assertFragment($relativeKey);
        $full = $this->prefix() . $relativeKey;
        if ((int) $this->raw(['EXISTS', $full]) === 0) {
            return null;
        }
        $d = $this->describe($full);
        $d['preview'] = null;
        $d['elements'] = null;
        try {
            $d['elements'] = match ($d['type']) {
                'hash' => (int) $this->raw(['HLEN', $full]),
                'list' => (int) $this->raw(['LLEN', $full]),
                'set' => (int) $this->raw(['SCARD', $full]),
                'zset' => (int) $this->raw(['ZCARD', $full]),
                'stream' => (int) $this->raw(['XLEN', $full]),
                default => null,
            };
            if (!$d['sensitive'] && $d['type'] === 'string') {
                $value = (string) $this->raw(['GETRANGE', $full, '0', '511']);
                $printable = mb_check_encoding($value, 'UTF-8') ? $value : '[binary ' . strlen($value) . ' bytes]';
                // cached values may embed secrets (e.g. resolved settings): hide anything that looks sensitive
                if (preg_match(self::SENSITIVE_WORDS, $printable)) {
                    $d['sensitive'] = true;
                } else {
                    $d['preview'] = Redactor::redactString($printable);
                }
            }
        } catch (Throwable) {
        }
        return $d;
    }

    /**
     * Deletes keys of the cache namespace only (`<prefix>cache:<sub>*`) via SCAN + UNLINK.
     *
     * @return int number of deleted keys
     */
    public function clearCache(string $subPrefix, ?string $actorId): int
    {
        if ($this->cacheSeparate()) {
            return $this->instance('cache')->clearCache($subPrefix, $actorId);
        }
        $this->assertFragment($subPrefix);
        $pattern = $this->cachePrefix() . $subPrefix . '*';
        if (!str_starts_with($pattern, $this->cachePrefix())) {
            throw ValidationException::single('namespace', 'Only the cache namespace can be cleared.');
        }
        $deleted = 0;
        $cursor = '0';
        $batches = 0;
        do {
            [$cursor, $keys] = (array) $this->raw(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '1000']);
            $cursor = (string) $cursor;
            // defence in depth: never delete anything outside the cache namespace
            $keys = array_values(array_filter(array_map('strval', (array) $keys), fn(string $k) => str_starts_with($k, $this->cachePrefix())));
            if ($keys !== []) {
                $deleted += (int) $this->raw(['UNLINK', ...$keys]);
            }
            $batches++;
        } while ($cursor !== '0' && $batches < 100000);

        $this->audit->record('redis.cache.cleared', 'redis_namespace', 'cache:' . $subPrefix, metadata: [
            'pattern' => 'cache:' . $subPrefix . '*', 'deleted' => $deleted,
        ], category: 'operations', severity: 'notice', actorId: $actorId);
        return $deleted;
    }

    /** Namespace statistics (sampled with SCAN, bounded). @return array<string, array{label:string, keys:int, truncated:bool}> */
    public function namespaceCounts(int $maxPerNamespace = 20000): array
    {
        $out = [];
        foreach (self::NAMESPACES as $ns => $label) {
            if (($ns === 'cache:') !== ($this->role() === 'cache') && ($this->cacheSeparate() || $this->role() === 'cache')) {
                continue; // the namespace lives on the other instance
            }
            $count = 0;
            $cursor = '0';
            do {
                [$cursor, $keys] = (array) $this->raw(['SCAN', $cursor, 'MATCH', $this->prefix() . $ns . '*', 'COUNT', '1000']);
                $cursor = (string) $cursor;
                $count += count((array) $keys);
            } while ($cursor !== '0' && $count < $maxPerNamespace);
            $out[$ns] = ['label' => $label, 'keys' => $count, 'truncated' => $cursor !== '0'];
        }
        return $out;
    }

    public function isSensitiveKey(string $relativeKey): bool
    {
        foreach (self::SENSITIVE_PREFIXES as $p) {
            if (str_starts_with($relativeKey, $p)) {
                return true;
            }
        }
        return (bool) preg_match(self::SENSITIVE_WORDS, $relativeKey);
    }

    /** @return array{key:string, type:string, ttl:int, size:?int, namespace:string, sensitive:bool} */
    private function describe(string $full): array
    {
        $rel = $this->relative($full);
        $size = null;
        try {
            $size = (int) $this->raw(['MEMORY', 'USAGE', $full, 'SAMPLES', '0']);
        } catch (Throwable) {
        }
        $ns = 'other';
        foreach (array_keys(self::NAMESPACES) as $candidate) {
            if (str_starts_with($rel, $candidate)) {
                $ns = rtrim($candidate, ':');
                break;
            }
        }
        return [
            'key' => $rel,
            'type' => (string) $this->raw(['TYPE', $full]),
            'ttl' => (int) $this->raw(['TTL', $full]),
            'size' => $size,
            'namespace' => $ns,
            'sensitive' => $this->isSensitiveKey($rel),
        ];
    }

    private function relative(string $full): string
    {
        return str_starts_with($full, $this->prefix()) ? substr($full, strlen($this->prefix())) : $full;
    }

    private function assertFragment(string $fragment): void
    {
        if (!preg_match(self::SAFE_FRAGMENT, $fragment)) {
            throw ValidationException::single('prefix', 'Use letters, digits and : _ . - only (no glob characters).');
        }
    }

    /** @param list<string> $args */
    private function raw(array $args): mixed
    {
        $client = $this->client();
        $error = false;
        if ($client instanceof Client) {
            $response = $client->executeRaw($args, $error);
        } else {
            $id = array_shift($args);
            $response = $client->executeCommand(new RawCommand((string) $id, $args));
            $error = $response instanceof \Predis\Response\ErrorInterface;
            $args = [$id, ...$args];
        }
        if ($error) {
            throw new \RuntimeException('Redis ' . strtoupper((string) $args[0]) . ' failed: ' . (string) $response);
        }
        return $response;
    }

    private function client(): ClientInterface
    {
        // Unprefixed client: SCAN MATCH patterns and returned key names are absolute.
        return $this->client ??= RedisClientFactory::create($this->config, false);
    }
}
