<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections;

use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Read-only overview of any named connection for Admin → Databases: server version, size and the list of
 * tables / collections with row (document) counts and sizes where the engine reports them cheaply.
 *
 * Author: ShokirjonMK
 */
final class DatabaseInspector
{
    private const MAX_OBJECTS = 500;

    public function __construct(private readonly DatabaseManager $dbs) {}

    /**
     * @return array{ok:bool, error:?string, version:?string, size:?int, objects:list<array{name:string, kind:string, rows:?int, size:?int}>, kind:string}
     */
    public function inspect(string $name): array
    {
        $d = $this->dbs->definition($name);
        $kind = $d->family() === 'document' ? 'collections' : 'tables';
        try {
            $result = match ($d->family()) {
                'sql' => $this->sql($d, $this->dbs->sql($name)),
                'document' => $this->mongo($name),
                'olap' => $this->clickhouse($name),
            };
            return ['ok' => true, 'error' => null, 'kind' => $kind] + $result;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => DatabaseManager::safeError($e, $d), 'version' => null, 'size' => null, 'objects' => [], 'kind' => $kind];
        }
    }

    private function sql(ConnectionDefinition $d, ConnectionInterface $c): array
    {
        $version = $this->dbs->sqlVersion($c, $d->driver);
        $size = null;
        $objects = [];
        switch ($d->driver) {
            case DatabaseDriver::PGSQL:
                $size = (int) $c->createCommand('SELECT pg_database_size(current_database())')->queryScalar();
                $rows = $c->createCommand(
                    "SELECT n.nspname || '.' || c.relname AS name, CASE c.relkind WHEN 'v' THEN 'view' WHEN 'm' THEN 'materialized view' WHEN 'p' THEN 'partitioned table' ELSE 'table' END AS kind,
                            GREATEST(c.reltuples, 0)::bigint AS rows, pg_total_relation_size(c.oid) AS size
                       FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                      WHERE c.relkind IN ('r','p','v','m') AND NOT c.relispartition AND n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_toast%'
                      ORDER BY 1 LIMIT " . self::MAX_OBJECTS,
                )->queryAll();
                break;
            case DatabaseDriver::MYSQL:
                $size = (int) $c->createCommand('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()')->queryScalar();
                $rows = $c->createCommand(
                    "SELECT table_name AS name, IF(table_type = 'VIEW', 'view', 'table') AS kind, table_rows AS `rows`, data_length + index_length AS size
                       FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name LIMIT " . self::MAX_OBJECTS,
                )->queryAll();
                break;
            case DatabaseDriver::SQLITE:
                $path = (string) ($d->option('path') ?: $d->database);
                $size = is_file($path) ? (int) filesize($path) : null;
                $rows = $c->createCommand("SELECT name, type AS kind, NULL AS rows, NULL AS size FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name LIMIT " . self::MAX_OBJECTS)->queryAll();
                break;
            default:
                // SQL Server / Oracle: names from the yiisoft/db schema (portable), no statistics
                $rows = array_map(static fn(string $t) => ['name' => $t, 'kind' => 'table', 'rows' => null, 'size' => null], array_slice($c->getSchema()->getTableNames('', true), 0, self::MAX_OBJECTS));
        }
        foreach ($rows as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            $objects[] = ['name' => (string) $r['name'], 'kind' => (string) $r['kind'], 'rows' => $r['rows'] === null ? null : (int) $r['rows'], 'size' => $r['size'] === null ? null : (int) $r['size']];
        }
        return ['version' => $version, 'size' => $size, 'objects' => $objects];
    }

    private function mongo(string $name): array
    {
        $client = $this->dbs->mongoClient($name);
        $db = $this->dbs->mongo($name);
        $stats = $db->command(['dbStats' => 1])->toArray()[0] ?? [];
        $objects = [];
        foreach ($db->listCollections() as $info) {
            $coll = $info->getName();
            if (count($objects) >= self::MAX_OBJECTS) {
                break;
            }
            $objects[] = ['name' => $coll, 'kind' => (string) ($info->getType() ?? 'collection'), 'rows' => $db->selectCollection($coll)->estimatedDocumentCount(), 'size' => null];
        }
        usort($objects, static fn($a, $b) => strcmp($a['name'], $b['name']));
        return ['version' => $this->dbs->mongoVersion($client), 'size' => isset($stats['storageSize']) ? (int) $stats['storageSize'] : null, 'objects' => $objects];
    }

    private function clickhouse(string $name): array
    {
        $ch = $this->dbs->clickhouse($name);
        $rows = $ch->select(
            'SELECT name, engine, total_rows, total_bytes FROM system.tables WHERE database = {db:String} ORDER BY name LIMIT ' . self::MAX_OBJECTS,
            ['db' => $ch->database()],
        );
        $objects = array_map(static fn(array $r) => [
            'name' => (string) $r['name'], 'kind' => (string) $r['engine'],
            'rows' => $r['total_rows'] === null ? null : (int) $r['total_rows'], 'size' => $r['total_bytes'] === null ? null : (int) $r['total_bytes'],
        ], $rows);
        $size = array_sum(array_map(static fn($o) => (int) $o['size'], $objects));
        return ['version' => $ch->version(), 'size' => $size, 'objects' => $objects];
    }
}
