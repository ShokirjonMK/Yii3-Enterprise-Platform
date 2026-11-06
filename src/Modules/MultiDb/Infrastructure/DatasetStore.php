<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Infrastructure;

/**
 * Storage of one dataset in its own database. Implementations: SqlDatasetStore (any yiisoft/db driver),
 * MongoDatasetStore, ClickHouseDatasetStore. Values passed in are already validated (Field::normalize()).
 *
 * Author: ShokirjonMK
 */
interface DatasetStore
{
    /** Creates the table / collection and indexes when missing. Returns true when something was created. */
    public function install(): bool;

    /**
     * @param array<string, mixed> $filters exact matches on ENUM / BOOL fields (already normalized)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function search(string $q, array $filters, int $page, int $perPage, string $sortKey, bool $desc): array;

    /** @return array<string,mixed>|null raw row with a string "id" */
    public function find(string $id): ?array;

    /** @param array<string,mixed> $values @return string new id */
    public function insert(array $values): string;

    /** Bulk load (seeding): one statement / request where the engine supports it. @param list<array<string,mixed>> $rows */
    public function insertMany(array $rows): int;

    /** @param array<string,mixed> $values only the changed fields */
    public function update(string $id, array $values): bool;

    public function delete(string $id): bool;

    public function count(): int;

    /** Engine-specific summary (aggregates computed by the database itself). @return list<array{label:string, value:string}> */
    public function stats(): array;
}
