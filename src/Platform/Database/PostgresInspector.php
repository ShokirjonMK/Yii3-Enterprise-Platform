<?php

declare(strict_types=1);

namespace App\Platform\Database;

use App\Platform\Audit\AuditLogger;
use App\Platform\Queue\JobQueue;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\DatabaseRouter;
use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Logging\Redactor;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Read-mostly PostgreSQL diagnostics for the Control Center (12 · PostgreSQL Management) plus a very
 * small set of SAFE maintenance operations:
 *   - ANALYZE / VACUUM (ANALYZE) of a table that exists in pg_stat_user_tables (whitelist, quoted),
 *   - pg_cancel_backend() of a client backend owned by the application role in the current database,
 *   - enqueueing the partition-maintenance job.
 * There is deliberately no terminate / drop / truncate / reindex operation.
 *
 * Every query text shown to operators is redacted (string literals masked, secrets removed) and truncated.
 */
final class PostgresInspector
{
    public const QUERY_PREVIEW = 600;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly DatabaseRouter $router,
        private readonly AuditLogger $audit,
        private readonly JobQueue $queue,
    ) {}

    /** Masks string literals and secret-looking fragments, then truncates. */
    public static function redactQuery(?string $sql, int $max = self::QUERY_PREVIEW): string
    {
        if ($sql === null || $sql === '') {
            return '';
        }
        $sql = (string) preg_replace("/E?'(?:[^']|'')*'/", "'***'", $sql);
        $sql = (string) preg_replace('/\$([A-Za-z_]*)\$.*?\$\1\$/s', '$$***$$', $sql);
        $sql = Redactor::redactString($sql);
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));
        return mb_strlen($sql) > $max ? mb_substr($sql, 0, $max) . '…' : $sql;
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT version() AS version,
                   current_setting('server_version') AS server_version,
                   current_database() AS database,
                   current_user AS role,
                   pg_postmaster_start_time() AS started_at,
                   EXTRACT(EPOCH FROM now() - pg_postmaster_start_time())::bigint AS uptime_seconds,
                   pg_database_size(current_database()) AS size_bytes,
                   current_setting('max_connections')::int AS max_connections,
                   (SELECT count(*) FROM pg_stat_activity) AS connections,
                   pg_is_in_recovery() AS in_recovery,
                   current_setting('wal_level') AS wal_level,
                   current_setting('archive_mode') AS archive_mode,
                   current_setting('shared_buffers') AS shared_buffers,
                   current_setting('statement_timeout') AS statement_timeout
            SQL)->queryOne() ?? [];
        $stats = $this->db->createCommand(<<<'SQL'
            SELECT xact_commit, xact_rollback, deadlocks, temp_bytes, conflicts,
                   CASE WHEN blks_hit + blks_read = 0 THEN NULL ELSE round(100.0 * blks_hit / (blks_hit + blks_read), 2) END AS cache_hit_pct
            FROM pg_stat_database WHERE datname = current_database()
            SQL)->queryOne() ?? [];
        $row['version'] = preg_replace('/ on .*/', '', (string) ($row['version'] ?? ''));
        $row['in_recovery'] = Sql::bool($row['in_recovery'] ?? false);
        return $row + $stats;
    }

    /** @return list<array{state:string, count:int}> */
    public function connectionsByState(): array
    {
        return array_map(static fn($r) => ['state' => (string) $r['state'], 'count' => (int) $r['c']], $this->db->createCommand(
            "SELECT coalesce(state, backend_type) AS state, count(*) AS c FROM pg_stat_activity GROUP BY 1 ORDER BY 2 DESC",
        )->queryAll());
    }

    /**
     * Sessions of the current database (plus background workers), query text redacted.
     *
     * @return list<array<string,mixed>>
     */
    public function activity(int $limit = 200, int $minDurationSeconds = 0, bool $activeOnly = false): array
    {
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT pid, usename, application_name, host(client_addr) AS client_addr, backend_type, state,
                   wait_event_type, wait_event, query_start, xact_start, state_change,
                   EXTRACT(EPOCH FROM now() - query_start)::numeric(12,1) AS duration_seconds,
                   query,
                   (usename = current_user AND datname = current_database() AND backend_type = 'client backend' AND pid <> pg_backend_pid()) AS cancellable,
                   pid = pg_backend_pid() AS is_self,
                   cardinality(pg_blocking_pids(pid)) AS blocked_by
            FROM pg_stat_activity
            WHERE (datname = current_database() OR datname IS NULL)
              AND (:active = false OR (state IS NOT NULL AND state <> 'idle'))
              AND (:min = 0 OR (query_start IS NOT NULL AND now() - query_start >= make_interval(secs => :min)))
            ORDER BY query_start NULLS LAST
            LIMIT :lim
            SQL, [':active' => $activeOnly, ':min' => $minDurationSeconds, ':lim' => $limit])->queryAll();
        foreach ($rows as &$r) {
            $r['query'] = self::redactQuery($r['query'] ?? null);
            $r['cancellable'] = Sql::bool($r['cancellable']);
            $r['is_self'] = Sql::bool($r['is_self']);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> active statements running longer than $seconds */
    public function longRunning(int $seconds = 30): array
    {
        return array_values(array_filter(
            $this->activity(100, $seconds, true),
            static fn($r) => !$r['is_self'] && ($r['backend_type'] ?? '') === 'client backend',
        ));
    }

    /** @return array{installed:bool, reason:?string} */
    public function statementsStatus(): array
    {
        $installed = (bool) $this->db->createCommand("SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements')")->queryScalar();
        if (!$installed) {
            return ['installed' => false, 'reason' => 'Extension pg_stat_statements is not installed in this database (CREATE EXTENSION pg_stat_statements; requires shared_preload_libraries).'];
        }
        try {
            $this->db->createCommand('SELECT 1 FROM pg_stat_statements LIMIT 1')->queryScalar();
            return ['installed' => true, 'reason' => null];
        } catch (Throwable $e) {
            return ['installed' => false, 'reason' => self::redactQuery($e->getMessage(), 300)];
        }
    }

    /**
     * Top statements from pg_stat_statements (normalised query text, constants already replaced by $n).
     *
     * @return list<array<string,mixed>>
     */
    public function slowQueries(string $order = 'mean', int $limit = 25): array
    {
        if (!$this->statementsStatus()['installed']) {
            return [];
        }
        $orderBy = match ($order) {
            'total' => 'total_exec_time',
            'calls' => 'calls',
            'rows' => 'rows',
            default => 'mean_exec_time',
        };
        $rows = $this->db->createCommand(
            "SELECT queryid::text AS queryid, calls, round(total_exec_time::numeric, 1) AS total_ms, round(mean_exec_time::numeric, 2) AS mean_ms,
                    round(max_exec_time::numeric, 1) AS max_ms, rows, shared_blks_hit, shared_blks_read, temp_blks_written, query
             FROM pg_stat_statements s JOIN pg_database d ON d.oid = s.dbid
             WHERE d.datname = current_database()
             ORDER BY $orderBy DESC LIMIT :lim",
            [':lim' => $limit],
        )->queryAll();
        foreach ($rows as &$r) {
            $r['query'] = self::redactQuery($r['query'] ?? null, 800);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function tables(int $limit = 100, string $order = 'size'): array
    {
        $orderBy = match ($order) {
            'dead' => 'n_dead_tup DESC',
            'seq' => 'seq_scan DESC',
            'name' => 'schemaname, relname',
            default => 'pg_total_relation_size(relid) DESC',
        };
        return $this->db->createCommand(
            "SELECT schemaname, relname, n_live_tup, n_dead_tup,
                    CASE WHEN n_live_tup + n_dead_tup = 0 THEN 0 ELSE round(100.0 * n_dead_tup / (n_live_tup + n_dead_tup), 1) END AS dead_pct,
                    pg_total_relation_size(relid) AS total_bytes, pg_relation_size(relid) AS table_bytes, pg_indexes_size(relid) AS index_bytes,
                    seq_scan, idx_scan, last_vacuum, last_autovacuum, last_analyze, last_autoanalyze
             FROM pg_stat_user_tables ORDER BY $orderBy LIMIT :lim",
            [':lim' => $limit],
        )->queryAll();
    }

    /** @return list<array<string,mixed>> */
    public function indexes(int $limit = 100): array
    {
        return $this->db->createCommand(<<<'SQL'
            SELECT s.schemaname, s.relname, s.indexrelname, s.idx_scan, s.idx_tup_read, pg_relation_size(s.indexrelid) AS size_bytes,
                   i.indisunique AS is_unique, i.indisprimary AS is_primary
            FROM pg_stat_user_indexes s JOIN pg_index i ON i.indexrelid = s.indexrelid
            ORDER BY pg_relation_size(s.indexrelid) DESC LIMIT :lim
            SQL, [':lim' => $limit])->queryAll();
    }

    /** Never-scanned, non-unique indexes (candidates for review — statistics reset resets the counters). */
    public function unusedIndexes(int $limit = 50): array
    {
        return $this->db->createCommand(<<<'SQL'
            SELECT s.schemaname, s.relname, s.indexrelname, pg_relation_size(s.indexrelid) AS size_bytes, pg_get_indexdef(s.indexrelid) AS definition
            FROM pg_stat_user_indexes s JOIN pg_index i ON i.indexrelid = s.indexrelid
            WHERE s.idx_scan = 0 AND NOT i.indisunique AND NOT i.indisprimary
            ORDER BY pg_relation_size(s.indexrelid) DESC LIMIT :lim
            SQL, [':lim' => $limit])->queryAll();
    }

    /**
     * Bloat estimate from dead tuples (pg_stat_user_tables): wasted ≈ table size × dead / (live + dead).
     *
     * @return list<array<string,mixed>>
     */
    public function bloat(int $limit = 30): array
    {
        return $this->db->createCommand(<<<'SQL'
            SELECT schemaname, relname, n_live_tup, n_dead_tup,
                   round(100.0 * n_dead_tup / greatest(n_live_tup + n_dead_tup, 1), 1) AS dead_pct,
                   (pg_relation_size(relid) * n_dead_tup / greatest(n_live_tup + n_dead_tup, 1))::bigint AS wasted_bytes_estimate,
                   last_autovacuum, last_vacuum
            FROM pg_stat_user_tables WHERE n_dead_tup > 0
            ORDER BY n_dead_tup DESC LIMIT :lim
            SQL, [':lim' => $limit])->queryAll();
    }

    /**
     * Blocked sessions and the sessions blocking them.
     *
     * @return list<array<string,mixed>>
     */
    public function blocking(): array
    {
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT blocked.pid AS blocked_pid, blocked.usename AS blocked_user, blocked.query AS blocked_query,
                   EXTRACT(EPOCH FROM now() - blocked.query_start)::numeric(12,1) AS waiting_seconds,
                   blocking.pid AS blocking_pid, blocking.usename AS blocking_user, blocking.state AS blocking_state, blocking.query AS blocking_query
            FROM pg_stat_activity blocked
            JOIN LATERAL unnest(pg_blocking_pids(blocked.pid)) AS b(pid) ON true
            JOIN pg_stat_activity blocking ON blocking.pid = b.pid
            ORDER BY waiting_seconds DESC NULLS LAST
            SQL)->queryAll();
        foreach ($rows as &$r) {
            $r['blocked_query'] = self::redactQuery($r['blocked_query'] ?? null, 300);
            $r['blocking_query'] = self::redactQuery($r['blocking_query'] ?? null, 300);
        }
        return $rows;
    }

    /** @return list<array{mode:string, granted:bool, count:int}> */
    public function lockSummary(): array
    {
        return array_map(static fn($r) => ['mode' => (string) $r['mode'], 'granted' => Sql::bool($r['granted']), 'count' => (int) $r['c']], $this->db->createCommand(
            'SELECT mode, granted, count(*) AS c FROM pg_locks l JOIN pg_database d ON d.oid = l.database WHERE d.datname = current_database() GROUP BY 1, 2 ORDER BY 3 DESC',
        )->queryAll());
    }

    /** @return array{in_recovery:bool, standbys:list<array>, slots:list<array>, archiver:?array, replicas:list<array>, replica_count:int} */
    public function replication(): array
    {
        $inRecovery = Sql::bool($this->db->createCommand('SELECT pg_is_in_recovery()')->queryScalar());
        $standbys = $inRecovery ? [] : $this->db->createCommand(<<<'SQL'
            SELECT pid, usename, application_name, host(client_addr) AS client_addr, state, sync_state,
                   sent_lsn::text, write_lsn::text, flush_lsn::text, replay_lsn::text,
                   pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn)::bigint AS replay_lag_bytes,
                   EXTRACT(EPOCH FROM write_lag)::numeric(10,3) AS write_lag_s,
                   EXTRACT(EPOCH FROM flush_lag)::numeric(10,3) AS flush_lag_s,
                   EXTRACT(EPOCH FROM replay_lag)::numeric(10,3) AS replay_lag_s
            FROM pg_stat_replication ORDER BY application_name
            SQL)->queryAll();
        $slots = $this->db->createCommand(
            $inRecovery
                ? 'SELECT slot_name, slot_type, active, NULL::bigint AS retained_bytes, wal_status FROM pg_replication_slots ORDER BY slot_name'
                : 'SELECT slot_name, slot_type, active, pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn)::bigint AS retained_bytes, wal_status FROM pg_replication_slots ORDER BY slot_name',
        )->queryAll();
        $archiver = $this->db->createCommand(
            'SELECT archived_count, last_archived_wal, last_archived_time, failed_count, last_failed_wal, last_failed_time, stats_reset FROM pg_stat_archiver',
        )->queryOne() ?: null;
        $replicas = array_map(static function (array $r): array {
            $r['error'] = $r['error'] === null ? null : self::redactQuery($r['error'], 300);
            return $r;
        }, $this->router->replicaStatus());
        return [
            'in_recovery' => $inRecovery,
            'standbys' => $standbys,
            'slots' => $slots,
            'archiver' => $archiver,
            'replicas' => $replicas,
            'replica_count' => $this->router->replicaCount(),
        ];
    }

    /**
     * Partitions of a partitioned table (bounds, size, estimated rows).
     *
     * @return list<array<string,mixed>>
     */
    public function partitions(string $parent = 'audit_log'): array
    {
        return $this->db->createCommand(<<<'SQL'
            SELECT c.relname AS name, pg_get_expr(c.relpartbound, c.oid) AS bounds,
                   pg_total_relation_size(c.oid) AS size_bytes, greatest(c.reltuples, 0)::bigint AS rows_estimate
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            JOIN pg_class p ON p.oid = i.inhparent
            JOIN pg_namespace n ON n.oid = p.relnamespace
            WHERE p.relname = :parent AND n.nspname = current_schema()
            ORDER BY c.relname
            SQL, [':parent' => $parent])->queryAll();
    }

    /** @return list<string> "schema.table" names that maintenance operations may target */
    public function maintainableTables(): array
    {
        return array_map('strval', $this->db->createCommand(
            "SELECT schemaname || '.' || relname FROM pg_stat_user_tables ORDER BY 1",
        )->queryColumn());
    }

    /**
     * ANALYZE or VACUUM (ANALYZE) a single whitelisted table. VACUUM FULL / FREEZE are not offered.
     */
    public function maintain(string $qualifiedTable, string $operation, ?string $actorId): void
    {
        if (!in_array($operation, ['analyze', 'vacuum'], true)) {
            throw ValidationException::single('operation', 'Unsupported maintenance operation.');
        }
        if (!in_array($qualifiedTable, $this->maintainableTables(), true)) {
            throw ValidationException::single('table', 'Unknown table.');
        }
        if ($this->db->getTransaction() !== null) {
            throw new ConflictException('Maintenance cannot run inside a transaction.', 'in_transaction');
        }
        [$schema, $table] = explode('.', $qualifiedTable, 2);
        $quoter = $this->db->getQuoter();
        $target = $quoter->quoteTableName($schema) . '.' . $quoter->quoteTableName($table);
        $sql = $operation === 'vacuum' ? "VACUUM (ANALYZE) $target" : "ANALYZE $target";
        $started = microtime(true);
        $this->db->createCommand($sql)->execute();
        $this->audit->record(
            'db.' . $operation,
            'db_table',
            $qualifiedTable,
            metadata: ['operation' => $operation, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)],
            category: 'operations',
            severity: 'notice',
            actorId: $actorId,
        );
    }

    /**
     * pg_cancel_backend() for a client backend of the application role in this database only.
     * Foreign roles, other databases, background processes and the current session are refused.
     */
    public function cancelBackend(int $pid, ?string $actorId): bool
    {
        $row = $this->db->createCommand(
            'SELECT pid, usename, datname, backend_type, (usename = current_user AND datname = current_database() AND backend_type = \'client backend\' AND pid <> pg_backend_pid()) AS allowed
             FROM pg_stat_activity WHERE pid = :pid',
            [':pid' => $pid],
        )->queryOne();
        if ($row === null) {
            throw new NotFoundException('Backend not found (it may have finished already).');
        }
        if (!Sql::bool($row['allowed'])) {
            $this->audit->record('db.cancel_backend', 'db_backend', (string) $pid, metadata: [
                'reason' => 'not_application_backend', 'role' => $row['usename'], 'backend_type' => $row['backend_type'],
            ], result: 'denied', category: 'operations', severity: 'warning', actorId: $actorId);
            throw new AccessDeniedException('Only queries of the application database role in this database can be cancelled.');
        }
        $ok = Sql::bool($this->db->createCommand('SELECT pg_cancel_backend(:pid)', [':pid' => $pid])->queryScalar());
        $this->audit->record('db.cancel_backend', 'db_backend', (string) $pid, metadata: ['signalled' => $ok], result: $ok ? 'success' : 'failure', category: 'operations', severity: 'notice', actorId: $actorId);
        return $ok;
    }

    public function enqueuePartitionMaintenance(?string $actorId): string
    {
        $jobId = $this->queue->push('platform.maintenance.partitions', ['_manual' => true], [
            // one manual run per minute at most
            'idempotency_key' => 'manual:partitions:' . date('YmdHi'),
        ]);
        $this->audit->record('db.partitions.enqueued', 'job', $jobId, category: 'operations', severity: 'notice', actorId: $actorId);
        return $jobId;
    }

    // ------------------------------------------------------------------ search diagnostics

    /** @return list<array{name:string, installed:?string, available:?string}> */
    public function extensions(array $names = ['pg_trgm', 'pg_stat_statements', 'unaccent', 'pgcrypto', 'citext', 'btree_gin']): array
    {
        $out = [];
        foreach ($names as $name) {
            $row = $this->db->createCommand(
                'SELECT (SELECT extversion FROM pg_extension WHERE extname = :n) AS installed, (SELECT default_version FROM pg_available_extensions WHERE name = :n) AS available',
                [':n' => $name],
            )->queryOne();
            $out[] = ['name' => $name, 'installed' => $row['installed'] ?? null, 'available' => $row['available'] ?? null];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> trigram (GIN/GiST) and full-text indexes */
    public function searchIndexes(): array
    {
        return $this->db->createCommand(<<<'SQL'
            SELECT schemaname, tablename, indexname, indexdef,
                   CASE WHEN indexdef ~* '(gin|gist)_trgm_ops' THEN 'trigram' ELSE 'full-text' END AS kind,
                   pg_relation_size((quote_ident(schemaname) || '.' || quote_ident(indexname))::regclass) AS size_bytes
            FROM pg_indexes
            WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
              AND (indexdef ~* '(gin|gist)_trgm_ops' OR indexdef ~* 'to_tsvector' OR indexdef ~* 'tsvector')
            ORDER BY kind, tablename, indexname
            SQL)->queryAll();
    }

    /**
     * Diagnostics for a search term: trigram decomposition, tsvector/tsquery, and the plan + timing of the
     * platform's case-insensitive "contains" search on users (Sql::search) — shows whether an index is used.
     *
     * @return array<string,mixed>
     */
    public function searchDiagnostics(string $term): array
    {
        $term = mb_substr(trim($term), 0, 100);
        $trgm = (bool) $this->db->createCommand("SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm')")->queryScalar();
        $result = [
            'term' => $term,
            'trigram_available' => $trgm,
            'trigrams' => $trgm ? (string) $this->db->createCommand('SELECT show_trgm(:t)::text', [':t' => $term])->queryScalar() : null,
            'tsvector' => (string) $this->db->createCommand("SELECT to_tsvector('simple', :t)::text", [':t' => $term])->queryScalar(),
            'tsquery' => (string) $this->db->createCommand("SELECT plainto_tsquery('simple', :t)::text", [':t' => $term])->queryScalar(),
        ];
        $like = '%' . Sql::escapeLike(mb_strtolower($term)) . '%';
        $sql = 'SELECT id FROM users WHERE deleted_at IS NULL AND (lower(username::text) LIKE :q OR lower(full_name::text) LIKE :q OR lower(email::text) LIKE :q)';
        $plan = $this->db->createCommand('EXPLAIN (FORMAT JSON) ' . $sql, [':q' => $like])->queryScalar();
        $decoded = json_decode((string) $plan, true);
        $nodes = [];
        $walk = static function (array $node) use (&$walk, &$nodes): void {
            $nodes[] = trim(($node['Node Type'] ?? '?') . ' ' . ($node['Index Name'] ?? $node['Relation Name'] ?? ''));
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        if (isset($decoded[0]['Plan'])) {
            $walk($decoded[0]['Plan']);
        }
        $t = microtime(true);
        $count = (int) $this->db->createCommand('SELECT count(*) FROM (' . $sql . ') s', [':q' => $like])->queryScalar();
        $result['users_plan'] = $nodes;
        $result['users_uses_index'] = (bool) array_filter($nodes, static fn($n) => str_contains($n, 'Index') || str_contains($n, 'Bitmap'));
        $result['users_matches'] = $count;
        $result['users_ms'] = round((microtime(true) - $t) * 1000, 2);
        if ($trgm && $term !== '') {
            $result['similar_usernames'] = $this->db->createCommand(
                'SELECT username, round(similarity(username::text, :t)::numeric, 3) AS score FROM users WHERE deleted_at IS NULL ORDER BY similarity(username::text, :t) DESC LIMIT 5',
                [':t' => $term],
            )->queryAll();
        }
        return $result;
    }
}
