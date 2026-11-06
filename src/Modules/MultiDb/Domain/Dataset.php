<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Domain;

/**
 * A table / collection that lives in one named connection (Admin → Databases or DB_CONN_* in .env).
 * The same CRUD (Admin, Web, REST API, console) works for every dataset whatever the engine.
 *
 * Author: ShokirjonMK
 */
final readonly class Dataset
{
    public const ENGINE_SQL = 'sql';
    public const ENGINE_MONGO = 'mongo';
    public const ENGINE_CLICKHOUSE = 'clickhouse';

    /**
     * @param list<Field> $fields
     * @param list<string> $columns list columns (field names)
     * @param list<string> $search fields matched by the "q" search
     * @param list<string> $filters ENUM / BOOL fields offered as exact-match filters
     * @param 'int'|'uuid'|'objectid' $idType
     */
    public function __construct(
        public string $code,
        public string $connection,
        public string $engine,
        public string $table,
        public string $label,
        public string $singular,
        public string $description,
        public string $icon,
        public array $fields,
        public array $columns,
        public array $search,
        public string $titleField,
        public string $defaultSort,
        public string $idType,
        public array $filters = [],
    ) {}

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $f) {
            if ($f->name === $name) {
                return $f;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_map(static fn(Field $f) => $f->name, $this->fields);
    }

    /** @return list<string> sortable keys (fields + timestamps) */
    public function sortable(): array
    {
        $keys = array_values(array_filter($this->fieldNames(), fn($n) => $this->field($n)?->type !== Field::TAGS && $this->field($n)?->type !== Field::TEXT));
        return [...$keys, 'created_at', 'updated_at'];
    }

    /** "-price" → ["price", true] when allowed, otherwise the default sort. @return array{0:string,1:bool} */
    public function parseSort(?string $sort): array
    {
        $sort = $sort ?: $this->defaultSort;
        $desc = str_starts_with($sort, '-');
        $key = ltrim($sort, '-');
        if (!in_array($key, $this->sortable(), true)) {
            return $this->parseSort($this->defaultSort);
        }
        return [$key, $desc];
    }

    public function validId(string $id): bool
    {
        return match ($this->idType) {
            'int' => (bool) preg_match('/^[1-9]\d{0,17}$/', $id),
            'uuid' => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id),
            'objectid' => (bool) preg_match('/^[0-9a-f]{24}$/i', $id),
        };
    }

    public function engineLabel(): string
    {
        return match ($this->engine) {
            self::ENGINE_MONGO => 'MongoDB',
            self::ENGINE_CLICKHOUSE => 'ClickHouse',
            default => 'SQL',
        };
    }
}
