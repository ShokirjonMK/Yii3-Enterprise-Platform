<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Infrastructure;

use App\Modules\MultiDb\Domain\Dataset;
use App\Modules\MultiDb\Domain\Field;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;

/**
 * Dataset as a MongoDB collection: ObjectId keys, tags stored as a native array, timestamps as BSON dates,
 * search with a case-insensitive regex, statistics with the aggregation pipeline.
 *
 * Author: ShokirjonMK
 */
final class MongoDatasetStore implements DatasetStore
{
    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(
        private readonly Dataset $dataset,
        private readonly Database $db,
    ) {}

    public function install(): bool
    {
        $exists = iterator_count($this->db->listCollectionNames(['filter' => ['name' => $this->dataset->table]])) > 0;
        if (!$exists) {
            $this->db->createCollection($this->dataset->table);
        }
        $c = $this->collection();
        $c->createIndex(['created_at' => -1], ['name' => 'created_at_desc']);
        $c->createIndex(['tags' => 1], ['name' => 'tags']);
        $c->createIndex(['published' => 1, 'created_at' => -1], ['name' => 'published_created']);
        return !$exists;
    }

    public function search(string $q, array $filters, int $page, int $perPage, string $sortKey, bool $desc): array
    {
        $filter = $filters;
        if ($q !== '') {
            $rx = new Regex(preg_quote($q, '/'), 'i');
            $filter['$or'] = array_map(static fn(string $f) => [$f => $rx], $this->dataset->search);
        }
        $total = $this->collection()->countDocuments($filter);
        $cursor = $this->collection()->find($filter, [
            'sort' => [$sortKey => $desc ? -1 : 1, '_id' => 1],
            'skip' => ($page - 1) * $perPage,
            'limit' => $perPage,
            'typeMap' => self::TYPE_MAP,
        ]);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->row((array) $doc);
        }
        return ['items' => $items, 'total' => $total];
    }

    public function find(string $id): ?array
    {
        $doc = $this->collection()->findOne(['_id' => new ObjectId($id)], ['typeMap' => self::TYPE_MAP]);
        return $doc === null ? null : $this->row((array) $doc);
    }

    public function insert(array $values): string
    {
        $now = new UTCDateTime();
        $r = $this->collection()->insertOne($this->toDoc($values) + ['created_at' => $now, 'updated_at' => $now]);
        return (string) $r->getInsertedId();
    }

    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $now = new UTCDateTime();
        return $this->collection()->insertMany(array_values(array_map(fn(array $r) => $this->toDoc($r) + ['created_at' => $now, 'updated_at' => $now], $rows)))->getInsertedCount();
    }

    public function update(string $id, array $values): bool
    {
        $r = $this->collection()->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $this->toDoc($values) + ['updated_at' => new UTCDateTime()]],
        );
        return $r->getMatchedCount() > 0;
    }

    public function delete(string $id): bool
    {
        return $this->collection()->deleteOne(['_id' => new ObjectId($id)])->getDeletedCount() > 0;
    }

    public function count(): int
    {
        return $this->collection()->countDocuments();
    }

    public function stats(): array
    {
        $out = [
            ['label' => 'Documents', 'value' => (string) $this->count()],
            ['label' => 'Published', 'value' => (string) $this->collection()->countDocuments(['published' => true])],
        ];
        $top = $this->collection()->aggregate([
            ['$unwind' => '$tags'],
            ['$group' => ['_id' => '$tags', 'n' => ['$sum' => 1]]],
            ['$sort' => ['n' => -1, '_id' => 1]],
            ['$limit' => 5],
        ], ['typeMap' => self::TYPE_MAP]);
        foreach ($top as $t) {
            $out[] = ['label' => 'Tag #' . $t['_id'], 'value' => (string) $t['n']];
        }
        return $out;
    }

    private function collection(): Collection
    {
        return $this->db->selectCollection($this->dataset->table);
    }

    /** @param array<string,mixed> $values */
    private function toDoc(array $values): array
    {
        foreach ($values as $k => $v) {
            $type = $this->dataset->field($k)?->type;
            if ($v !== null && ($type === Field::DATETIME || $type === Field::DATE)) {
                $values[$k] = new UTCDateTime(new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')));
            }
        }
        return $values;
    }

    /** @param array<string,mixed> $doc */
    private function row(array $doc): array
    {
        $row = ['id' => (string) $doc['_id']];
        unset($doc['_id']);
        foreach ($doc as $k => $v) {
            $row[$k] = $v instanceof UTCDateTime ? $v->toDateTime()->format('Y-m-d H:i:s') : $v;
        }
        return $row;
    }
}
