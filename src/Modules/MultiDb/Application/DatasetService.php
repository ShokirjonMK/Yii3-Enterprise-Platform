<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Application;

use App\Modules\MultiDb\Domain\Dataset;
use App\Modules\MultiDb\Domain\Datasets;
use App\Modules\MultiDb\Domain\Field;
use App\Modules\MultiDb\Infrastructure\ClickHouseDatasetStore;
use App\Modules\MultiDb\Infrastructure\DatasetStore;
use App\Modules\MultiDb\Infrastructure\DemoSchema;
use App\Modules\MultiDb\Infrastructure\MongoDatasetStore;
use App\Modules\MultiDb\Infrastructure\SqlDatasetStore;
use App\Platform\Audit\AuditLogger;
use App\Platform\Database\Connections\DatabaseManager;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Database\Page;
use Throwable;

/**
 * One CRUD for every dataset, whatever database it lives in: validation (Field types), the engine store,
 * audit (in the platform database — the business row lives elsewhere, so the two are not one transaction).
 * Used by Admin, Web, REST API and the console alike.
 *
 * Author: ShokirjonMK
 */
final class DatasetService
{
    /** @var array<string, DatasetStore> */
    private array $stores = [];

    public function __construct(
        private readonly DatabaseManager $dbs,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, Dataset> */
    public function datasets(): array
    {
        return Datasets::all();
    }

    public function dataset(string $code): Dataset
    {
        return Datasets::get($code);
    }

    public function store(Dataset $d): DatasetStore
    {
        return $this->stores[$d->code] ??= match ($d->engine) {
            Dataset::ENGINE_SQL => new SqlDatasetStore($d, $this->dbs->sql($d->connection)),
            Dataset::ENGINE_MONGO => new MongoDatasetStore($d, $this->dbs->mongo($d->connection)),
            Dataset::ENGINE_CLICKHOUSE => new ClickHouseDatasetStore($d, $this->dbs->clickhouse($d->connection)),
        };
    }

    /**
     * Status card of every dataset: connection, engine, row count, statistics — or the error (never fatal).
     *
     * @return list<array{dataset:Dataset, driver:?string, ok:bool, count:?int, stats:array, error:?string}>
     */
    public function overview(bool $withStats = true): array
    {
        $out = [];
        foreach ($this->datasets() as $d) {
            /** @var array{dataset:Dataset, driver:?string, ok:bool, count:?int, stats:array, error:?string} $item */
            $item = ['dataset' => $d, 'driver' => null, 'ok' => false, 'count' => null, 'stats' => [], 'error' => null];
            try {
                if (!$this->dbs->has($d->connection)) {
                    throw new NotFoundException('Connection "' . $d->connection . '" is not configured (Admin → Databases or DB_CONN_* in .env).');
                }
                $item['driver'] = $this->dbs->definition($d->connection)->driver;
                $store = $this->store($d);
                $item['count'] = $store->count();
                $item['stats'] = $withStats ? $store->stats() : [];
                $item['ok'] = true;
            } catch (Throwable $e) {
                $item['error'] = $this->safeError($d, $e);
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Creates missing tables / collections and loads the sample rows into empty datasets.
     *
     * @return list<array{dataset:string, connection:string, created:bool, seeded:int, error:?string}>
     */
    public function install(bool $seed = true, ?string $only = null): array
    {
        $report = [];
        foreach ($this->datasets() as $d) {
            if ($only !== null && $d->code !== $only) {
                continue;
            }
            $r = ['dataset' => $d->code, 'connection' => $d->connection, 'created' => false, 'seeded' => 0, 'error' => null];
            try {
                $store = $this->store($d);
                $r['created'] = $store->install();
                if ($seed && $store->count() === 0) {
                    $rows = array_map(fn(array $raw) => $this->validate($d, $raw, false), DemoSchema::seed($d->code));
                    $r['seeded'] = $store->insertMany($rows);
                }
            } catch (Throwable $e) {
                $r['error'] = $this->safeError($d, $e);
            }
            $report[] = $r;
        }
        $this->audit->record('multidb.installed', 'multidb', null, null, ['report' => $report], category: 'system', severity: 'notice');
        return $report;
    }

    /** @param array<string,mixed> $filters raw filter values (only Dataset::$filters are used) */
    public function search(Dataset $d, string $q, array $filters, int $page, int $perPage, ?string $sort = null): Page
    {
        [$key, $desc] = $d->parseSort($sort);
        $clean = [];
        foreach ($d->filters as $name) {
            $raw = $filters[$name] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $f = $d->field($name);
            if ($f === null) {
                continue;
            }
            if ($f->type === Field::BOOL) {
                $clean[$name] = in_array($raw, ['1', 'true', 'yes', true, 1], true);
                continue;
            }
            [$v, $err] = $f->normalize($raw);
            if ($err !== null) {
                throw ValidationException::single('filter[' . $name . ']', $err);
            }
            $clean[$name] = $v;
        }
        $page = max(1, $page);
        $r = $this->store($d)->search(mb_substr(trim($q), 0, 100), $clean, $page, $perPage, $key, $desc);
        return new Page(array_map(fn(array $row) => $this->present($d, $row), $r['items']), $r['total'], $page, $perPage, ($desc ? '-' : '') . $key);
    }

    /** @return array<string,mixed> */
    public function get(Dataset $d, string $id): array
    {
        $row = $d->validId($id) ? $this->store($d)->find($id) : null;
        if ($row === null) {
            throw new NotFoundException(ucfirst($d->singular) . ' not found.');
        }
        return $this->present($d, $row);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(Dataset $d, array $input): array
    {
        $values = $this->validate($d, $input, false);
        $id = $this->store($d)->insert($values);
        $this->audit->record('multidb.' . $d->code . '.created', 'multidb.' . $d->code, $id, null, $values, ['connection' => $d->connection]);
        return $this->get($d, $id);
    }

    /**
     * @param array<string,mixed> $input
     * @param bool $partial PATCH semantics: only the given fields are validated and changed
     * @return array<string,mixed>
     */
    public function update(Dataset $d, string $id, array $input, bool $partial = false): array
    {
        $before = $this->get($d, $id);
        $values = $this->validate($d, $input, $partial);
        $changes = array_filter($values, static fn($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        if ($changes !== []) {
            if (!$this->store($d)->update($id, $changes)) {
                throw new NotFoundException(ucfirst($d->singular) . ' not found.');
            }
            $this->audit->record(
                'multidb.' . $d->code . '.updated',
                'multidb.' . $d->code,
                $id,
                array_intersect_key($before, $changes),
                $changes,
                ['connection' => $d->connection, 'fields' => array_keys($changes)],
            );
        }
        return $this->get($d, $id);
    }

    public function delete(Dataset $d, string $id): void
    {
        $before = $this->get($d, $id);
        if (!$this->store($d)->delete($id)) {
            throw new NotFoundException(ucfirst($d->singular) . ' not found.');
        }
        $this->audit->record('multidb.' . $d->code . '.deleted', 'multidb.' . $d->code, $id, $before, null, ['connection' => $d->connection], severity: 'notice');
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> normalized values
     */
    public function validate(Dataset $d, array $input, bool $partial): array
    {
        $values = [];
        $errors = [];
        foreach ($d->fields as $f) {
            if ($partial && !array_key_exists($f->name, $input)) {
                continue;
            }
            [$v, $err] = $f->normalize($input[$f->name] ?? null);
            if ($err !== null) {
                $errors[$f->name][] = $err;
            } else {
                $values[$f->name] = $v;
            }
        }
        $unknown = array_diff(array_keys($input), [...$d->fieldNames(), 'id', '_csrf', 'created_at', 'updated_at']);
        if ($unknown !== []) {
            $errors['_'][] = 'Unknown field(s): ' . implode(', ', array_slice($unknown, 0, 5)) . '.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $values;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function present(Dataset $d, array $row): array
    {
        $out = ['id' => (string) $row['id']];
        foreach ($d->fields as $f) {
            $out[$f->name] = $f->present($row[$f->name] ?? null);
        }
        foreach (['created_at', 'updated_at'] as $ts) {
            $out[$ts] = isset($row[$ts]) ? substr(str_replace('T', ' ', (string) $row[$ts]), 0, 19) : null;
        }
        return $out;
    }

    public function title(Dataset $d, array $row): string
    {
        $v = $row[$d->titleField] ?? '';
        return is_string($v) && $v !== '' ? $v : '#' . $row['id'];
    }

    private function safeError(Dataset $d, Throwable $e): string
    {
        try {
            return DatabaseManager::safeError($e, $this->dbs->definition($d->connection));
        } catch (Throwable) {
            return mb_substr($e->getMessage(), 0, 600);
        }
    }
}
