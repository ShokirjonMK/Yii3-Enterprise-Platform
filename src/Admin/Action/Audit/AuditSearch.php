<?php

declare(strict_types=1);

namespace App\Admin\Action\Audit;

use App\Platform\Access\AccessService;
use App\Platform\Identity\Domain\User;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Logging\Redactor;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * 20 · Audit log search: filter normalisation (whitelisted), query building with tenant isolation,
 * before/after diff and CSV row redaction. A time range is always applied so that the monthly
 * partitions are pruned.
 */
final class AuditSearch
{
    public const SORTABLE = ['occurred_at' => 'a.occurred_at', 'action' => 'a.action', 'category' => 'a.category', 'result' => 'a.result', 'severity' => 'a.severity'];
    public const RESULTS = ['success', 'denied', 'failure'];
    public const SEVERITIES = ['debug', 'info', 'notice', 'warning', 'critical'];
    public const EXPORT_LIMIT = 50000;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
    ) {}

    /** @return array<string,string> */
    public function normalize(array $q): array
    {
        $s = static fn(string $k, int $max = 128): string => mb_substr(trim((string) ($q[$k] ?? '')), 0, $max);
        $f = [
            'from' => $s('from', 20), 'to' => $s('to', 20), 'actor' => $s('actor'), 'action' => $s('action'),
            'category' => $s('category', 32), 'result' => $s('result', 16), 'severity' => $s('severity', 16),
            'entity_type' => $s('entity_type', 96), 'entity_id' => $s('entity_id', 96), 'correlation_id' => $s('correlation_id'),
            'q' => $s('q', 200),
        ];
        foreach (['from', 'to'] as $k) {
            if ($f[$k] !== '' && self::date($f[$k]) === null) {
                $f[$k] = '';
            }
        }
        if (!in_array($f['result'], self::RESULTS, true)) {
            $f['result'] = '';
        }
        if (!in_array($f['severity'], self::SEVERITIES, true)) {
            $f['severity'] = '';
        }
        if (preg_match('/^[a-z0-9_.-]*$/i', $f['category']) !== 1) {
            $f['category'] = '';
        }
        if ($f['from'] === '' && $f['to'] === '') {
            $f['from'] = (new DateTimeImmutable($f['correlation_id'] !== '' || $f['entity_id'] !== '' ? '-90 days' : '-7 days'))->format('Y-m-d');
        }
        return $f;
    }

    public function query(User $actor, array $f): Query
    {
        $q = (new Query($this->db))
            ->select(['a.id', 'a.occurred_at', 'a.category', 'a.action', 'a.result', 'a.severity', 'a.actor_id', 'a.actor_type', 'a.tenant_id',
                'a.entity_type', 'a.entity_id', 'ip' => new Expression('host(a.ip)'), 'a.user_agent', 'a.correlation_id', 'a.before', 'a.after', 'a.metadata',
                'actor' => new Expression('COALESCE(a.actor_name, u.username)')])
            ->from(['a' => 'audit_log'])
            ->leftJoin(['u' => 'users'], 'u.id = a.actor_id');
        if (($from = self::date($f['from'] ?? '')) !== null) {
            $q->andWhere(['>=', 'a.occurred_at', $from->format('Y-m-d H:i:sP')]);
        }
        if (($to = self::date($f['to'] ?? '', true)) !== null) {
            $q->andWhere(['<', 'a.occurred_at', $to->format('Y-m-d H:i:sP')]);
        }
        $actorFilter = (string) ($f['actor'] ?? '');
        if ($actorFilter !== '') {
            if (preg_match('/^[0-9a-f-]{36}$/i', $actorFilter) === 1) {
                $q->andWhere(['a.actor_id' => $actorFilter]);
            } elseif ($actorFilter === 'system') {
                $q->andWhere(['a.actor_id' => null]);
            } else {
                $q->andWhere(['or', ['ilike', 'a.actor_name', $actorFilter], new Expression('lower(u.username) = lower(:au)', [':au' => $actorFilter])]);
            }
        }
        if (($f['action'] ?? '') !== '') {
            // prefix match: "auth." finds every authentication action
            $q->andWhere(new Expression('a.action LIKE :ap', [':ap' => Sql::escapeLike((string) $f['action']) . '%']));
        }
        foreach (['category', 'result', 'severity', 'entity_type', 'entity_id', 'correlation_id'] as $k) {
            if (($f[$k] ?? '') !== '') {
                $q->andWhere(['a.' . $k => $f[$k]]);
            }
        }
        if (($f['q'] ?? '') !== '') {
            $q->andWhere(\App\Platform\Infrastructure\OpsSql::contains((string) $f['q'], ['a.action', 'a.entity_id', 'a.actor_name', 'a.correlation_id', 'a.metadata', 'a.before', 'a.after']));
        }
        $tenant = $this->tenant($actor);
        if ($tenant !== null) {
            $q->andWhere(['a.tenant_id' => $tenant]);
        }
        return $q;
    }

    public function find(User $actor, int $id): ?array
    {
        $q = (new Query($this->db))->select(['a.*', 'ip_host' => new Expression('host(a.ip)'), 'actor' => new Expression('COALESCE(a.actor_name, u.username)')])
            ->from(['a' => 'audit_log'])->leftJoin(['u' => 'users'], 'u.id = a.actor_id')->where(['a.id' => $id]);
        $tenant = $this->tenant($actor);
        if ($tenant !== null) {
            $q->andWhere(['a.tenant_id' => $tenant]);
        }
        return $q->one();
    }

    /** @return list<array{path:string, before:mixed, after:mixed, change:string}> */
    public static function diff(mixed $before, mixed $after): array
    {
        $b = self::flatten(is_array($before) ? $before : ($before === null ? [] : ['value' => $before]));
        $a = self::flatten(is_array($after) ? $after : ($after === null ? [] : ['value' => $after]));
        $out = [];
        foreach (array_unique([...array_keys($b), ...array_keys($a)]) as $path) {
            $hasB = array_key_exists($path, $b);
            $hasA = array_key_exists($path, $a);
            $change = match (true) {
                $hasB && !$hasA => 'removed',
                !$hasB && $hasA => 'added',
                $b[$path] !== $a[$path] => 'changed',
                default => 'same',
            };
            $out[] = ['path' => (string) $path, 'before' => $b[$path] ?? null, 'after' => $a[$path] ?? null, 'change' => $change];
        }
        usort($out, static fn($x, $y) => [$x['change'] === 'same', $x['path']] <=> [$y['change'] === 'same', $y['path']]);
        return $out;
    }

    /** CSV export row: re-redacted (defence in depth) and protected against CSV formula injection. */
    public static function csvRow(array $r): array
    {
        $json = static fn($v) => $v === null ? '' : (string) json_encode(Redactor::redact(Sql::json($v, null)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cells = [
            $r['id'], $r['occurred_at'], $r['category'], $r['action'], $r['result'], $r['severity'], $r['actor_id'], $r['actor'], $r['actor_type'],
            $r['tenant_id'], $r['entity_type'], $r['entity_id'], $r['ip'], Redactor::redactString((string) $r['user_agent']), $r['correlation_id'],
            $json($r['before']), $json($r['after']), $json($r['metadata']),
        ];
        return array_map(static function ($v): string {
            $v = (string) ($v ?? '');
            return $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
        }, $cells);
    }

    public const CSV_HEADER = ['id', 'occurred_at', 'category', 'action', 'result', 'severity', 'actor_id', 'actor', 'actor_type', 'tenant_id',
        'entity_type', 'entity_id', 'ip', 'user_agent', 'correlation_id', 'before', 'after', 'metadata'];

    /** Partitions of audit_log with size, estimated rows and bounds, plus detached archive tables. */
    public function partitions(): array
    {
        $attached = $this->db->createCommand(<<<'SQL'
            SELECT c.relname AS name, pg_get_expr(c.relpartbound, c.oid) AS bounds, pg_total_relation_size(c.oid) AS bytes,
                   GREATEST(c.reltuples, 0)::bigint AS est_rows, true AS attached
            FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid JOIN pg_class p ON p.oid = i.inhparent
            WHERE p.relname = 'audit_log' ORDER BY c.relname DESC
            SQL)->queryAll();
        $detached = $this->db->createCommand(<<<'SQL'
            SELECT c.relname AS name, NULL AS bounds, pg_total_relation_size(c.oid) AS bytes, GREATEST(c.reltuples, 0)::bigint AS est_rows, false AS attached
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname ~ '^audit_log_p[0-9]{6}$' AND NOT c.relispartition
            ORDER BY c.relname DESC
            SQL)->queryAll();
        return [...$attached, ...$detached];
    }

    private function tenant(User $actor): ?string
    {
        return $this->access->can($actor->id, 'tenants.cross') ? null : $actor->tenantId;
    }

    private static function date(string $v, bool $endOfDay = false): ?DateTimeImmutable
    {
        if ($v === '') {
            return null;
        }
        $tz = new \DateTimeZone('Asia/Tashkent');
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d'] as $fmt) {
            $d = DateTimeImmutable::createFromFormat('!' . $fmt, $v, $tz);
            if ($d !== false) {
                return $fmt === 'Y-m-d' && $endOfDay ? $d->modify('+1 day') : $d;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function flatten(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v) && $v !== [] && !array_is_list($v)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $v;
            }
        }
        return $out;
    }
}
