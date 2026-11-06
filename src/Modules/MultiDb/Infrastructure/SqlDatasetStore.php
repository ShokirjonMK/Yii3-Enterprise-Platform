<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Infrastructure;

use App\Modules\MultiDb\Domain\Dataset;
use App\Modules\MultiDb\Domain\Field;
use App\Shared\Domain\Exception\ValidationException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Dataset in any SQL connection (PostgreSQL, MySQL/MariaDB, SQLite, …) through the regular yiisoft/db
 * Query builder — the same code that works on the platform database works on every other SQL connection.
 *
 * Author: ShokirjonMK
 */
final class SqlDatasetStore implements DatasetStore
{
    public function __construct(
        private readonly Dataset $dataset,
        private readonly ConnectionInterface $db,
    ) {}

    public function install(): bool
    {
        if ($this->db->getSchema()->getTableSchema($this->dataset->table, true) !== null) {
            return false;
        }
        foreach (DemoSchema::sql($this->dataset->code, $this->db->getDriverName()) as $sql) {
            $this->db->createCommand($sql)->execute();
        }
        $this->db->getSchema()->refresh();
        return true;
    }

    public function search(string $q, array $filters, int $page, int $perPage, string $sortKey, bool $desc): array
    {
        $query = (new Query($this->db))->from($this->dataset->table);
        if ($q !== '') {
            // LIKE '%q%' with escaped wildcards; caseSensitive:false → ILIKE on PostgreSQL, LOWER() elsewhere
            $or = ['or'];
            foreach ($this->dataset->search as $f) {
                $or[] = ['like', $f, $q, 'caseSensitive' => false];
            }
            $query->andWhere($or);
        }
        foreach ($filters as $k => $v) {
            $query->andWhere([$k => is_bool($v) ? (int) $v : $v]);
        }
        $total = (int) (clone $query)->count();
        $items = $query
            ->orderBy([$sortKey => $desc ? SORT_DESC : SORT_ASC, 'id' => SORT_ASC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();
        return ['items' => array_values(array_map($this->row(...), $items)), 'total' => $total];
    }

    public function find(string $id): ?array
    {
        $row = (new Query($this->db))->from($this->dataset->table)->where(['id' => $id])->one();
        return $row === null ? null : $this->row($row);
    }

    public function insert(array $values): string
    {
        $values = $this->toDb($values);
        if ($this->dataset->idType === 'uuid') {
            $values = ['id' => Ids::uuid()] + $values;
        }
        try {
            $this->db->createCommand()->insert($this->dataset->table, $values)->execute();
        } catch (IntegrityException $e) {
            throw $this->duplicate($e);
        }
        return $this->dataset->idType === 'uuid' ? (string) $values['id'] : $this->db->getLastInsertId();
    }

    public function insertMany(array $rows): int
    {
        return $this->db->transaction(function () use ($rows): int {
            foreach ($rows as $r) {
                $this->insert($r);
            }
            return count($rows);
        });
    }

    public function update(string $id, array $values): bool
    {
        $values = $this->toDb($values) + ['updated_at' => new Expression('CURRENT_TIMESTAMP')];
        try {
            $n = $this->db->createCommand()->update($this->dataset->table, $values, ['id' => $id])->execute();
        } catch (IntegrityException $e) {
            throw $this->duplicate($e);
        }
        // MySQL reports 0 affected rows when nothing changed — existence decides
        return $n > 0 || $this->find($id) !== null;
    }

    public function delete(string $id): bool
    {
        return $this->db->createCommand()->delete($this->dataset->table, ['id' => $id])->execute() > 0;
    }

    public function count(): int
    {
        return (int) (new Query($this->db))->from($this->dataset->table)->count();
    }

    public function stats(): array
    {
        $q = fn(string $sql) => $this->db->createCommand($sql)->queryOne() ?? [];
        return match ($this->dataset->code) {
            'products' => (function () use ($q): array {
                $r = $q('SELECT COUNT(*) n, COALESCE(SUM(price * stock), 0) stock_value, COALESCE(SUM(CASE WHEN stock < 5 THEN 1 ELSE 0 END), 0) low, COALESCE(SUM(active), 0) active FROM products');
                return [
                    ['label' => 'Products', 'value' => (string) (int) ($r['n'] ?? 0)],
                    ['label' => 'Active', 'value' => (string) (int) ($r['active'] ?? 0)],
                    ['label' => 'Stock value', 'value' => number_format((float) ($r['stock_value'] ?? 0), 2, '.', ' ')],
                    ['label' => 'Low stock (< 5)', 'value' => (string) (int) ($r['low'] ?? 0)],
                ];
            })(),
            'kpis' => (function (): array {
                $rows = $this->db->createCommand(
                    'SELECT DISTINCT ON (metric) metric, period, value, unit FROM kpi_metrics ORDER BY metric, period DESC',
                )->queryAll();
                return array_map(static fn(array $r) => [
                    'label' => ucfirst(str_replace('_', ' ', (string) $r['metric'])) . ' (' . substr((string) $r['period'], 0, 7) . ')',
                    'value' => number_format((float) $r['value'], 2, '.', ' ') . ' ' . $r['unit'],
                ], $rows);
            })(),
            'orders' => (function (): array {
                $rows = $this->db->createCommand(
                    'SELECT status, currency, COUNT(*) n, SUM(total) amount FROM archived_orders GROUP BY status, currency ORDER BY status, currency',
                )->queryAll();
                return array_map(static fn(array $r) => [
                    'label' => ucfirst((string) $r['status']) . ' · ' . $r['currency'],
                    'value' => (int) $r['n'] . ' / ' . number_format((float) $r['amount'], 2, '.', ' '),
                ], $rows);
            })(),
            default => [['label' => 'Rows', 'value' => (string) $this->count()]],
        };
    }

    /** @param array<string,mixed> $values */
    private function toDb(array $values): array
    {
        foreach ($values as $k => $v) {
            $type = $this->dataset->field((string) $k)?->type;
            if ($type === Field::BOOL) {
                $values[$k] = $v ? 1 : 0;
            } elseif ($type === Field::TAGS) {
                $values[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }
        return $values;
    }

    /** @param array<string,mixed> $row */
    private function row(array $row): array
    {
        $row['id'] = (string) $row['id'];
        return $row;
    }

    private function duplicate(IntegrityException $e): ValidationException
    {
        $field = DemoSchema::uniqueField($this->dataset->code);
        if ($field === null || !preg_match('/duplicate|unique/i', $e->getMessage())) {
            return new ValidationException(['_' => ['The database rejected the values (constraint violation).']]);
        }
        $label = $this->dataset->field($field)?->label ?? $field;
        return ValidationException::single($field, $label . ' already exists.');
    }
}
