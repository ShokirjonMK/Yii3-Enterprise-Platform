<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Domain;

use App\Shared\Domain\Exception\NotFoundException;

/**
 * The five demo datasets — one per extra connection, each a different engine and shape:
 *
 *   products   → shop      (MariaDB / MySQL driver)   auto-increment id, DECIMAL money, boolean flag
 *   kpis       → analytics (PostgreSQL #2)            uuid id, unique (metric, period), numeric values
 *   orders     → archive   (PostgreSQL #3)            uuid id, closed orders moved out of the hot database
 *   articles   → docs      (MongoDB)                  ObjectId, schemaless document with a tags array
 *   pageviews  → events    (ClickHouse)               append-optimised MergeTree, edits are mutations
 *
 * Author: ShokirjonMK
 */
final class Datasets
{
    /** @var array<string, Dataset>|null */
    private static ?array $all = null;

    /** @return array<string, Dataset> */
    public static function all(): array
    {
        return self::$all ??= self::build();
    }

    public static function get(string $code): Dataset
    {
        return self::all()[$code] ?? throw new NotFoundException('Unknown dataset "' . $code . '".');
    }

    /** @return array<string, Dataset> */
    private static function build(): array
    {
        $list = [
            new Dataset(
                code: 'products',
                connection: 'shop',
                engine: Dataset::ENGINE_SQL,
                table: 'products',
                label: 'Products',
                singular: 'product',
                description: 'Shop catalogue in MariaDB (MySQL driver): SKU, price, stock.',
                icon: 'box',
                fields: [
                    new Field('sku', 'SKU', Field::STRING, true, 32, hint: 'Unique, e.g. EL-1001'),
                    new Field('name', 'Name', Field::STRING, true, 200),
                    new Field('category', 'Category', Field::ENUM, true, options: ['electronics', 'books', 'clothing', 'home', 'sport']),
                    new Field('price', 'Price', Field::DECIMAL, true, min: 0),
                    new Field('stock', 'Stock', Field::INT, true, min: 0),
                    new Field('active', 'Active', Field::BOOL),
                ],
                columns: ['sku', 'name', 'category', 'price', 'stock', 'active'],
                search: ['sku', 'name'],
                titleField: 'name',
                defaultSort: 'name',
                idType: 'int',
                filters: ['category', 'active'],
            ),
            new Dataset(
                code: 'kpis',
                connection: 'analytics',
                engine: Dataset::ENGINE_SQL,
                table: 'kpi_metrics',
                label: 'KPI metrics',
                singular: 'KPI value',
                description: 'Monthly business indicators in the analytics PostgreSQL server.',
                icon: 'activity',
                fields: [
                    new Field('metric', 'Metric', Field::ENUM, true, options: ['revenue', 'orders', 'new_users', 'churn_rate', 'nps']),
                    new Field('period', 'Period', Field::DATE, true, hint: 'First day of the month'),
                    new Field('value', 'Value', Field::DECIMAL, true),
                    new Field('unit', 'Unit', Field::ENUM, true, options: ['UZS', 'USD', 'count', 'percent']),
                    new Field('note', 'Note', Field::TEXT, false, 1000),
                ],
                columns: ['metric', 'period', 'value', 'unit'],
                search: ['metric', 'note'],
                titleField: 'metric',
                defaultSort: '-period',
                idType: 'uuid',
                filters: ['metric', 'unit'],
            ),
            new Dataset(
                code: 'orders',
                connection: 'archive',
                engine: Dataset::ENGINE_SQL,
                table: 'archived_orders',
                label: 'Archived orders',
                singular: 'archived order',
                description: 'Closed orders kept in the archive PostgreSQL server.',
                icon: 'archive',
                fields: [
                    new Field('order_no', 'Order no.', Field::STRING, true, 32, hint: 'Unique, e.g. ORD-2026-0001'),
                    new Field('customer', 'Customer', Field::STRING, true, 160),
                    new Field('total', 'Total', Field::DECIMAL, true, min: 0),
                    new Field('currency', 'Currency', Field::ENUM, true, options: ['UZS', 'USD', 'EUR']),
                    new Field('status', 'Status', Field::ENUM, true, options: ['delivered', 'cancelled', 'refunded']),
                    new Field('closed_on', 'Closed on', Field::DATE, true),
                ],
                columns: ['order_no', 'customer', 'total', 'currency', 'status', 'closed_on'],
                search: ['order_no', 'customer'],
                titleField: 'order_no',
                defaultSort: '-closed_on',
                idType: 'uuid',
                filters: ['status', 'currency'],
            ),
            new Dataset(
                code: 'articles',
                connection: 'docs',
                engine: Dataset::ENGINE_MONGO,
                table: 'articles',
                label: 'Articles',
                singular: 'article',
                description: 'Knowledge-base documents in MongoDB (free text and a tags array).',
                icon: 'file',
                fields: [
                    new Field('title', 'Title', Field::STRING, true, 200),
                    new Field('author', 'Author', Field::STRING, false, 120),
                    new Field('body', 'Body', Field::TEXT, true, 20000),
                    new Field('tags', 'Tags', Field::TAGS, hint: 'Comma separated'),
                    new Field('published', 'Published', Field::BOOL),
                ],
                columns: ['title', 'author', 'tags', 'published'],
                search: ['title', 'author', 'body'],
                titleField: 'title',
                defaultSort: '-created_at',
                idType: 'objectid',
                filters: ['published'],
            ),
            new Dataset(
                code: 'pageviews',
                connection: 'events',
                engine: Dataset::ENGINE_CLICKHOUSE,
                table: 'page_views',
                label: 'Page views',
                singular: 'page view',
                description: 'Web analytics events in ClickHouse (MergeTree; edits and deletes are synchronous mutations).',
                icon: 'stream',
                fields: [
                    new Field('viewed_at', 'Viewed at', Field::DATETIME, true),
                    new Field('path', 'Path', Field::STRING, true, 500),
                    new Field('country', 'Country', Field::ENUM, true, options: ['UZ', 'KZ', 'RU', 'US', 'DE', 'TR']),
                    new Field('device', 'Device', Field::ENUM, true, options: ['desktop', 'mobile', 'tablet']),
                    new Field('duration_ms', 'Duration (ms)', Field::INT, true, min: 0),
                    new Field('referrer', 'Referrer', Field::STRING, false, 255),
                ],
                columns: ['viewed_at', 'path', 'country', 'device', 'duration_ms'],
                search: ['path', 'referrer'],
                titleField: 'path',
                defaultSort: '-viewed_at',
                idType: 'uuid',
                filters: ['country', 'device'],
            ),
        ];
        $out = [];
        foreach ($list as $d) {
            $out[$d->code] = $d;
        }
        return $out;
    }
}
