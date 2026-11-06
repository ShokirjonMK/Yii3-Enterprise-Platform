<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections;

use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ValidationException;
use MongoDB\BSON\Document;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Type;
use MongoDB\BSON\UTCDateTime;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Query console of Admin → Databases.
 *
 * Read mode (databases.query): one statement, SELECT/WITH/SHOW/EXPLAIN/DESCRIBE/VALUES (SQL, ClickHouse) or
 * find/aggregate/count (MongoDB). Enforced twice: the statement is checked, and it runs inside a READ ONLY transaction
 * (PostgreSQL, MySQL/MariaDB), PRAGMA query_only (SQLite), readonly=2 (ClickHouse) and is always rolled back.
 * Write mode (databases.write, superadmin by default): any statement / insert/update/delete/command, committed.
 * A connection marked read-only never accepts write mode. Every run is audited with the statement text.
 *
 * MongoDB input is JSON:
 *   {"collection": "files", "find": {"owner": "x"}, "sort": {"_id": -1}, "projection": {"body": 0}}
 *   {"collection": "files", "aggregate": [{"$group": {"_id": "$owner", "n": {"$sum": 1}}}]}
 *   {"collection": "files", "count": {}}
 *   write: insertOne / insertMany / updateMany {"filter","update"} / deleteMany {filter} / {"command": {...}}
 *
 * Author: ShokirjonMK
 */
final class QueryConsole
{
    public const MAX_ROWS = 1000;
    private const READ_KEYWORDS = ['select', 'with', 'show', 'explain', 'describe', 'desc', 'values', 'table', 'pragma', 'exists'];

    public function __construct(
        private readonly DatabaseManager $dbs,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{columns:list<string>, rows:list<array<string,string|null>>, truncated:bool, affected:?int, duration_ms:int, error:?string}
     */
    public function run(string $actorId, string $name, string $statement, bool $write, int $limit = 100): array
    {
        $d = $this->dbs->definition($name);
        $statement = trim($statement);
        $limit = max(1, min(self::MAX_ROWS, $limit));
        if ($statement === '') {
            throw ValidationException::single('statement', 'Enter a statement.');
        }
        if (mb_strlen($statement) > 100_000) {
            throw ValidationException::single('statement', 'The statement is too long (100 000 characters max).');
        }
        if ($write && $d->readOnly) {
            throw new AccessDeniedException('This connection is read-only.');
        }
        if (!$write && $d->family() !== 'document') {
            self::assertReadOnlySql($statement);
        }

        $t = hrtime(true);
        $error = null;
        $result = ['columns' => [], 'rows' => [], 'truncated' => false, 'affected' => null];
        try {
            $result = match ($d->family()) {
                'sql' => $this->sql($d, $this->dbs->sql($name), $statement, $write, $limit),
                'olap' => $this->clickhouse($name, $statement, $write, $limit),
                'document' => $this->mongo($name, $statement, $write, $limit),
            };
        } catch (ValidationException|AccessDeniedException $e) {
            throw $e;
        } catch (Throwable $e) {
            $error = DatabaseManager::safeError($e, $d);
        }
        $ms = (int) ((hrtime(true) - $t) / 1e6);

        $this->audit->record(
            $write ? 'database.query.write' : 'database.query.read',
            'database_connection',
            $name,
            null,
            null,
            ['driver' => $d->driver, 'statement' => mb_substr($statement, 0, 4000), 'rows' => count($result['rows']), 'affected' => $result['affected'], 'duration_ms' => $ms, 'error' => $error],
            result: $error === null ? 'success' : 'failure',
            category: 'data',
            severity: $write ? 'warning' : 'notice',
            actorId: $actorId,
        );
        return $result + ['duration_ms' => $ms, 'error' => $error];
    }

    /** One statement whose first keyword reads data. Comments and string literals are ignored for the check. */
    public static function assertReadOnlySql(string $sql): void
    {
        $clean = preg_replace(["~/\*.*?\*/~s", '~--[^\n]*~', "~'(?:''|[^'])*'~", '~"(?:""|[^"])*"~', '~`[^`]*`~'], ' ', $sql) ?? '';
        $clean = rtrim(trim($clean), "; \t\n\r");
        if (str_contains($clean, ';')) {
            throw ValidationException::single('statement', 'Read mode runs exactly one statement.');
        }
        $first = strtolower((string) (preg_split('/[\s(]+/', ltrim($clean, " \t\n\r("), 2)[0] ?? ''));
        if (!in_array($first, self::READ_KEYWORDS, true)) {
            throw ValidationException::single('statement', 'Read mode allows SELECT, WITH, SHOW, EXPLAIN, DESCRIBE and VALUES only. Switch to write mode (superadmin) to change data.');
        }
        if ($first === 'pragma' && str_contains($clean, '=')) {
            throw ValidationException::single('statement', 'Read mode cannot change PRAGMA settings.');
        }
    }

    private function sql(ConnectionDefinition $d, ConnectionInterface $c, string $sql, bool $write, int $limit): array
    {
        $sql = rtrim($sql, "; \t\n\r");
        if ($write) {
            $tx = $c->beginTransaction();
            try {
                $out = $this->fetch($c, $sql, $limit, true);
                $tx->commit();
                return $out;
            } catch (Throwable $e) {
                $tx->rollBack();
                throw $e;
            }
        }
        // read mode: server-enforced read-only transaction, always rolled back
        switch ($d->driver) {
            case DatabaseDriver::MYSQL:
                $c->createCommand('START TRANSACTION READ ONLY')->execute();
                try {
                    return $this->fetch($c, $sql, $limit, false);
                } finally {
                    $c->createCommand('ROLLBACK')->execute();
                }
            case DatabaseDriver::SQLITE:
                $c->createCommand('PRAGMA query_only = ON')->execute();
                try {
                    return $this->fetch($c, $sql, $limit, false);
                } finally {
                    if (!$d->readOnly) {
                        $c->createCommand('PRAGMA query_only = OFF')->execute();
                    }
                }
            default:
                $tx = $c->beginTransaction();
                try {
                    if ($d->driver === DatabaseDriver::PGSQL) {
                        $c->createCommand('SET TRANSACTION READ ONLY')->execute();
                    }
                    return $this->fetch($c, $sql, $limit, false);
                } finally {
                    $tx->rollBack();
                }
        }
    }

    private function fetch(ConnectionInterface $c, string $sql, int $limit, bool $write): array
    {
        $pdo = $c->getActivePdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $columns[] = (string) ($stmt->getColumnMeta($i)['name'] ?? "col$i");
        }
        if ($columns === []) {
            return ['columns' => [], 'rows' => [], 'truncated' => false, 'affected' => $stmt->rowCount()];
        }
        $rows = [];
        $truncated = false;
        while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
            if (count($rows) >= $limit) {
                $truncated = true;
                break;
            }
            $rows[] = array_combine(self::uniqueColumns($columns), array_map(self::cell(...), $row));
        }
        $stmt->closeCursor();
        return ['columns' => self::uniqueColumns($columns), 'rows' => $rows, 'truncated' => $truncated, 'affected' => $write ? $stmt->rowCount() : null];
    }

    private function clickhouse(string $name, string $sql, bool $write, int $limit): array
    {
        $ch = $this->dbs->clickhouse($name);
        $sql = rtrim($sql, "; \t\n\r");
        if ($write && !in_array(strtolower((string) preg_split('/\s+/', ltrim($sql), 2)[0]), self::READ_KEYWORDS, true)) {
            $ch->execute($sql);
            return ['columns' => [], 'rows' => [], 'truncated' => false, 'affected' => null];
        }
        $settings = ['max_result_rows' => $limit + 1, 'result_overflow_mode' => 'break'] + ($write ? [] : ['readonly' => 2]);
        $r = $ch->selectWithMeta($sql, [], $settings);
        $columns = array_map(static fn($m) => (string) $m['name'], $r['meta']);
        $rows = array_map(static fn(array $row) => array_map(self::cell(...), $row), array_slice($r['data'], 0, $limit));
        return ['columns' => $columns, 'rows' => $rows, 'truncated' => count($r['data']) > $limit, 'affected' => null];
    }

    private function mongo(string $name, string $json, bool $write, int $limit): array
    {
        $q = json_decode($json, true);
        if (!is_array($q)) {
            throw ValidationException::single('statement', 'MongoDB queries are JSON, e.g. {"collection": "users", "find": {}}.');
        }
        $db = $this->dbs->mongo($name);
        if (isset($q['command'])) {
            if (!$write) {
                throw ValidationException::single('statement', 'Database commands need write mode.');
            }
            $docs = $db->command((array) $q['command'])->toArray();
            return self::documents($docs, $limit, null);
        }
        $collName = (string) ($q['collection'] ?? '');
        if (!preg_match('/^[^$\x00]{1,255}$/', $collName)) {
            throw ValidationException::single('statement', 'Set "collection".');
        }
        $coll = $db->selectCollection($collName);
        if (array_key_exists('find', $q)) {
            $opts = ['limit' => $limit + 1, 'maxTimeMS' => 30000];
            foreach (['sort', 'projection', 'skip'] as $k) {
                if (isset($q[$k])) {
                    $opts[$k] = $q[$k];
                }
            }
            return self::documents(iterator_to_array($coll->find((array) $q['find'] ?: [], $opts), false), $limit, null);
        }
        if (isset($q['aggregate'])) {
            $pipeline = array_values((array) $q['aggregate']);
            foreach ($pipeline as $stage) {
                if (!$write && is_array($stage) && (isset($stage['$out']) || isset($stage['$merge']))) {
                    throw ValidationException::single('statement', '$out and $merge write data — use write mode.');
                }
            }
            $pipeline[] = ['$limit' => $limit + 1];
            return self::documents(iterator_to_array($coll->aggregate($pipeline, ['maxTimeMS' => 30000]), false), $limit, null);
        }
        if (array_key_exists('count', $q)) {
            return self::documents([['count' => $coll->countDocuments((array) $q['count'] ?: [], ['maxTimeMS' => 30000])]], $limit, null);
        }
        $writeOps = ['insertOne', 'insertMany', 'updateMany', 'deleteMany'];
        foreach ($writeOps as $op) {
            if (isset($q[$op])) {
                if (!$write) {
                    throw ValidationException::single('statement', "$op changes data — use write mode.");
                }
                $affected = match ($op) {
                    'insertOne' => $coll->insertOne((array) $q[$op])->getInsertedCount(),
                    'insertMany' => $coll->insertMany(array_values((array) $q[$op]))->getInsertedCount(),
                    'updateMany' => $coll->updateMany((array) ($q[$op]['filter'] ?? []), (array) ($q[$op]['update'] ?? []))->getModifiedCount(),
                    'deleteMany' => $coll->deleteMany((array) $q[$op])->getDeletedCount(),
                };
                return ['columns' => [], 'rows' => [], 'truncated' => false, 'affected' => (int) $affected];
            }
        }
        throw ValidationException::single('statement', 'Use one of: find, aggregate, count' . ($write ? ', insertOne, insertMany, updateMany, deleteMany, command' : '') . '.');
    }

    private static function documents(array $docs, int $limit, ?int $affected): array
    {
        $truncated = count($docs) > $limit;
        $docs = array_slice($docs, 0, $limit);
        $columns = [];
        $rows = [];
        foreach ($docs as $doc) {
            $doc = self::bson($doc);
            $row = [];
            foreach ((array) $doc as $k => $v) {
                $columns[$k] = true;
                $row[(string) $k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : self::cell($v);
            }
            $rows[] = $row;
        }
        $columns = array_map('strval', array_keys($columns));
        $rows = array_map(static fn(array $r) => array_merge(array_fill_keys($columns, null), $r), $rows);
        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated, 'affected' => $affected];
    }

    private static function bson(mixed $v): mixed
    {
        return match (true) {
            $v instanceof ObjectId => (string) $v,
            $v instanceof UTCDateTime => $v->toDateTime()->format('Y-m-d\TH:i:s.vP'),
            $v instanceof Document => self::bson($v->toPHP(['root' => 'array', 'document' => 'array', 'array' => 'array'])),
            $v instanceof Type => json_encode($v),
            is_object($v) => self::bson((array) $v),
            is_array($v) => array_map(self::bson(...), $v),
            default => $v,
        };
    }

    private static function cell(mixed $v): ?string
    {
        return match (true) {
            $v === null => null,
            is_bool($v) => $v ? 'true' : 'false',
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_resource($v) => '[binary]',
            default => mb_strimwidth((string) $v, 0, 2000, '…'),
        };
    }

    /** @param list<string> $columns @return list<string> */
    private static function uniqueColumns(array $columns): array
    {
        $seen = [];
        foreach ($columns as $i => $c) {
            $n = $c;
            $k = 2;
            while (isset($seen[$n])) {
                $n = $c . '_' . $k++;
            }
            $seen[$n] = true;
            $columns[$i] = $n;
        }
        return $columns;
    }
}
