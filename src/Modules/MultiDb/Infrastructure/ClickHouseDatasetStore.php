<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Infrastructure;

use App\Modules\MultiDb\Domain\Dataset;
use App\Modules\MultiDb\Domain\Field;
use App\Platform\Database\Connections\ClickHouse\ClickHouseClient;

/**
 * Dataset as a ClickHouse MergeTree table. Inserts are batched JSONEachRow; edits and deletes are mutations run
 * synchronously (mutations_sync = 1) so the next read sees them. Every value travels as a typed server-side
 * parameter ({name:Type}); only whitelisted identifiers are written into the SQL text.
 *
 * Author: ShokirjonMK
 */
final class ClickHouseDatasetStore implements DatasetStore
{
    private const SYNC = ['mutations_sync' => 1];

    public function __construct(
        private readonly Dataset $dataset,
        private readonly ClickHouseClient $ch,
    ) {}

    public function install(): bool
    {
        $exists = (int) $this->ch->scalar(
            'SELECT count() FROM system.tables WHERE database = currentDatabase() AND name = {t:String}',
            ['t' => $this->dataset->table],
        ) > 0;
        if (!$exists) {
            foreach (DemoSchema::clickhouse($this->dataset->code) as $sql) {
                $this->ch->execute($sql);
            }
        }
        return !$exists;
    }

    public function search(string $q, array $filters, int $page, int $perPage, string $sortKey, bool $desc): array
    {
        [$where, $params] = $this->where($q, $filters);
        $t = $this->dataset->table;
        $total = (int) $this->ch->scalar("SELECT count() FROM $t $where", $params);
        $items = $this->ch->select(
            "SELECT * FROM $t $where ORDER BY $sortKey " . ($desc ? 'DESC' : 'ASC') . ', id LIMIT {limit:UInt32} OFFSET {offset:UInt64}',
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );
        return ['items' => $items, 'total' => $total];
    }

    public function find(string $id): ?array
    {
        return $this->ch->select("SELECT * FROM {$this->dataset->table} WHERE id = {id:UUID} LIMIT 1", ['id' => $id])[0] ?? null;
    }

    public function insert(array $values): string
    {
        $row = $this->toRow($values);
        $this->ch->insert($this->dataset->table, [$row]);
        return (string) $row['id'];
    }

    /** One JSONEachRow request → one MergeTree part (ClickHouse prefers few large inserts). */
    public function insertMany(array $rows): int
    {
        return $this->ch->insert($this->dataset->table, array_values(array_map($this->toRow(...), $rows)));
    }

    /** @param array<string,mixed> $values */
    private function toRow(array $values): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = ['id' => Ids::uuid()];
        foreach ($this->dataset->fields as $f) {
            $row[$f->name] = $this->toCh($f, $values[$f->name] ?? null);
        }
        return $row + ['created_at' => $now, 'updated_at' => $now];
    }

    public function update(string $id, array $values): bool
    {
        if ($this->find($id) === null) {
            return false;
        }
        $set = [];
        $params = ['id' => $id];
        foreach ($values as $k => $v) {
            $f = $this->dataset->field($k);
            if ($f === null) {
                continue;
            }
            $set[] = "$k = {v_$k:" . $this->chType($f) . '}';
            $params["v_$k"] = $this->toCh($f, $v);
        }
        $set[] = 'updated_at = now()';
        $this->ch->execute("ALTER TABLE {$this->dataset->table} UPDATE " . implode(', ', $set) . ' WHERE id = {id:UUID}', $params, self::SYNC);
        return true;
    }

    public function delete(string $id): bool
    {
        if ($this->find($id) === null) {
            return false;
        }
        $this->ch->execute("ALTER TABLE {$this->dataset->table} DELETE WHERE id = {id:UUID}", ['id' => $id], self::SYNC);
        return true;
    }

    public function count(): int
    {
        return (int) $this->ch->scalar("SELECT count() FROM {$this->dataset->table}");
    }

    public function stats(): array
    {
        $t = $this->dataset->table;
        $sum = $this->ch->select("SELECT count() n, round(avg(duration_ms)) avg_ms, uniqExact(path) paths, max(viewed_at) last FROM $t")[0] ?? [];
        $out = [
            ['label' => 'Views', 'value' => (string) (int) ($sum['n'] ?? 0)],
            ['label' => 'Distinct paths', 'value' => (string) (int) ($sum['paths'] ?? 0)],
            ['label' => 'Avg. duration', 'value' => (int) ($sum['avg_ms'] ?? 0) . ' ms'],
        ];
        foreach ($this->ch->select("SELECT country, count() n FROM $t GROUP BY country ORDER BY n DESC, country LIMIT 3") as $r) {
            $out[] = ['label' => 'Country ' . $r['country'], 'value' => (string) (int) $r['n']];
        }
        return $out;
    }

    /** @return array{0:string, 1:array<string,mixed>} */
    private function where(string $q, array $filters): array
    {
        $cond = [];
        $params = [];
        if ($q !== '') {
            $or = array_map(static fn(string $f) => "positionCaseInsensitiveUTF8($f, {q:String}) > 0", $this->dataset->search);
            $cond[] = '(' . implode(' OR ', $or) . ')';
            $params['q'] = $q;
        }
        foreach ($filters as $k => $v) {
            $f = $this->dataset->field($k);
            if ($f !== null) {
                $cond[] = "$k = {f_$k:" . $this->chType($f) . '}';
                $params["f_$k"] = $this->toCh($f, $v);
            }
        }
        return [$cond === [] ? '' : 'WHERE ' . implode(' AND ', $cond), $params];
    }

    private function chType(Field $f): string
    {
        return match ($f->type) {
            Field::INT => 'Int64',
            Field::DECIMAL => 'Decimal(18, 2)',
            Field::BOOL => 'Bool',
            Field::DATE => 'Date',
            Field::DATETIME => 'DateTime',
            Field::TAGS => 'Array(String)',
            default => 'String',
        };
    }

    private function toCh(Field $f, mixed $v): mixed
    {
        return match ($f->type) {
            Field::INT => (int) $v,
            Field::BOOL => (bool) $v,
            Field::TAGS => (array) $v,
            default => $v ?? '',
        };
    }
}
