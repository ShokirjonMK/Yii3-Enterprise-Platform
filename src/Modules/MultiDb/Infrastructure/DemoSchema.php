<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Infrastructure;

/**
 * DDL and sample data of the demo datasets, per engine. Each database gets its own native types and constraints
 * (MariaDB AUTO_INCREMENT / ON UPDATE, PostgreSQL uuid + CHECK + UNIQUE, MongoDB indexes, ClickHouse MergeTree).
 *
 * Author: ShokirjonMK
 */
final class DemoSchema
{
    /** @return list<string> */
    public static function sql(string $dataset, string $driver): array
    {
        return match ("$dataset:$driver") {
            'products:mysql' => [<<<'SQL'
                CREATE TABLE IF NOT EXISTS products (
                    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    sku        VARCHAR(32)   NOT NULL,
                    name       VARCHAR(200)  NOT NULL,
                    category   VARCHAR(32)   NOT NULL,
                    price      DECIMAL(14,2) NOT NULL DEFAULT 0,
                    stock      INT           NOT NULL DEFAULT 0,
                    active     TINYINT(1)    NOT NULL DEFAULT 1,
                    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY products_sku_uq (sku),
                    KEY products_category_idx (category, name),
                    CONSTRAINT products_price_chk CHECK (price >= 0),
                    CONSTRAINT products_stock_chk CHECK (stock >= 0)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL],
            'kpis:pgsql' => [
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS kpi_metrics (
                    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                    metric     varchar(32)   NOT NULL,
                    period     date          NOT NULL,
                    value      numeric(16,2) NOT NULL,
                    unit       varchar(16)   NOT NULL,
                    note       text,
                    created_at timestamptz   NOT NULL DEFAULT now(),
                    updated_at timestamptz   NOT NULL DEFAULT now(),
                    CONSTRAINT kpi_metrics_metric_period_uq UNIQUE (metric, period)
                )
                SQL,
                'CREATE INDEX IF NOT EXISTS kpi_metrics_period_idx ON kpi_metrics (period DESC)',
            ],
            'orders:pgsql' => [
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS archived_orders (
                    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                    order_no   varchar(32)   NOT NULL UNIQUE,
                    customer   varchar(160)  NOT NULL,
                    total      numeric(14,2) NOT NULL CHECK (total >= 0),
                    currency   char(3)       NOT NULL,
                    status     varchar(16)   NOT NULL CHECK (status IN ('delivered', 'cancelled', 'refunded')),
                    closed_on  date          NOT NULL,
                    created_at timestamptz   NOT NULL DEFAULT now(),
                    updated_at timestamptz   NOT NULL DEFAULT now()
                )
                SQL,
                'CREATE INDEX IF NOT EXISTS archived_orders_closed_idx ON archived_orders (closed_on DESC)',
                'CREATE INDEX IF NOT EXISTS archived_orders_status_idx ON archived_orders (status)',
            ],
            default => throw new \RuntimeException("No demo schema for dataset \"$dataset\" on driver \"$driver\"."),
        };
    }

    /** @return list<string> */
    public static function clickhouse(string $dataset): array
    {
        return match ($dataset) {
            // ORDER BY id only: every other column stays updatable by ALTER … UPDATE (key columns are not)
            'pageviews' => [<<<'SQL'
                CREATE TABLE IF NOT EXISTS page_views (
                    id          UUID,
                    viewed_at   DateTime,
                    path        String,
                    country     LowCardinality(String),
                    device      LowCardinality(String),
                    duration_ms UInt32,
                    referrer    String DEFAULT '',
                    created_at  DateTime DEFAULT now(),
                    updated_at  DateTime DEFAULT now()
                ) ENGINE = MergeTree ORDER BY id
                SQL],
            default => throw new \RuntimeException("No ClickHouse schema for dataset \"$dataset\"."),
        };
    }

    /** Field reported when a UNIQUE constraint rejects a row. */
    public static function uniqueField(string $dataset): ?string
    {
        return ['products' => 'sku', 'kpis' => 'period', 'orders' => 'order_no'][$dataset] ?? null;
    }

    /** @return array<array-key, array<string,mixed>> sample rows (raw form values, validated like user input) */
    public static function seed(string $dataset): array
    {
        return match ($dataset) {
            'products' => self::products(),
            'kpis' => self::kpis(),
            'orders' => self::orders(),
            'articles' => self::articles(),
            'pageviews' => self::pageviews(),
            default => [],
        };
    }

    private static function products(): array
    {
        $rows = [
            ['EL-1001', 'Noutbuk 14" Pro', 'electronics', '9450000.00', 12],
            ['EL-1002', 'Smartfon X5', 'electronics', '4200000.00', 30],
            ['EL-1003', 'Simsiz quloqchin', 'electronics', '650000.00', 3],
            ['BK-2001', 'O\'zbek adabiyoti antologiyasi', 'books', '120000.00', 45],
            ['BK-2002', 'PHP 8.4 amaliy qo\'llanma', 'books', '185000.00', 2],
            ['CL-3001', 'Paxta futbolka', 'clothing', '95000.00', 120],
            ['CL-3002', 'Qishki kurtka', 'clothing', '780000.00', 8],
            ['HM-4001', 'Choynak (elektr)', 'home', '310000.00', 25],
            ['HM-4002', 'Atlas yostiq jildi', 'home', '75000.00', 60],
            ['SP-5001', 'Futbol to\'pi', 'sport', '210000.00', 0],
            ['SP-5002', 'Yoga gilamchasi', 'sport', '160000.00', 18],
            ['SP-5003', 'Velosiped shlemi', 'sport', '390000.00', 4],
        ];
        return array_map(static fn(array $r) => [
            'sku' => $r[0], 'name' => $r[1], 'category' => $r[2], 'price' => $r[3], 'stock' => (string) $r[4], 'active' => $r[4] > 0 ? '1' : '',
        ], $rows);
    }

    private static function kpis(): array
    {
        $base = ['revenue' => [1_250_000_000, 'UZS'], 'orders' => [4200, 'count'], 'new_users' => [980, 'count'], 'churn_rate' => [3.4, 'percent'], 'nps' => [41, 'count']];
        $out = [];
        for ($m = 5; $m >= 0; $m--) {
            $period = (new \DateTimeImmutable('first day of this month'))->modify("-$m month")->format('Y-m-d');
            foreach ($base as $metric => [$v, $unit]) {
                $growth = 1 + (5 - $m) * ($metric === 'churn_rate' ? -0.03 : 0.04);
                $out[] = ['metric' => $metric, 'period' => $period, 'value' => number_format($v * $growth, 2, '.', ''), 'unit' => $unit,
                    'note' => $m === 0 ? 'Current month (preliminary)' : ''];
            }
        }
        return $out;
    }

    private static function orders(): array
    {
        $customers = ['Aziz Karimov', 'Dilnoza Rahimova', 'Bekzod Tursunov', 'Malika Yusupova', 'Sardor Aliyev', 'Nodira Qodirova', 'TechMarket MChJ', 'Ilm Nashriyoti'];
        $statuses = ['delivered', 'delivered', 'delivered', 'cancelled', 'refunded'];
        $out = [];
        for ($i = 1; $i <= 15; $i++) {
            $out[] = [
                'order_no' => sprintf('ORD-2026-%04d', $i),
                'customer' => $customers[$i % count($customers)],
                'total' => number_format(150000 + ($i * 73919) % 4_000_000, 2, '.', ''),
                'currency' => $i % 5 === 0 ? 'USD' : 'UZS',
                'status' => $statuses[$i % count($statuses)],
                'closed_on' => (new \DateTimeImmutable('today'))->modify('-' . (5 + $i * 9) . ' days')->format('Y-m-d'),
            ];
        }
        return $out;
    }

    private static function articles(): array
    {
        return [
            ['title' => 'Platformaga yangi modul qo\'shish', 'author' => 'ShokirjonMK', 'tags' => 'guide, modules, gii', 'published' => '1',
                'body' => "Enterprise Gii orqali modul generatsiya qilinadi: ./yii gii:module.\nRBAC, audit, outbox va testlar avtomatik yaratiladi."],
            ['title' => 'Bir nechta baza bilan ishlash', 'author' => 'ShokirjonMK', 'tags' => 'database, guide', 'published' => '1',
                'body' => 'DatabaseManager: $dbs->sql(\'shop\'), $dbs->mongo(\'docs\'), $dbs->clickhouse(\'events\'). Ulanishlar Admin → Databases da boshqariladi.'],
            ['title' => 'Kafka outbox namunasi', 'author' => 'Platform team', 'tags' => 'kafka, events', 'published' => '1',
                'body' => 'Har bir o\'zgarish bitta tranzaksiyada outbox jadvaliga yoziladi, relay uni Kafka\'ga yuboradi.'],
            ['title' => 'Redis: state va cache ajratilgan', 'author' => 'Platform team', 'tags' => 'redis, ops', 'published' => '1',
                'body' => 'Sessiyalar va rate limit — redis (persist), kesh — redis-cache (LRU).'],
            ['title' => 'API hujjatlariga kirish', 'author' => 'Security', 'tags' => 'api, security', 'published' => '1',
                'body' => 'Swagger faqat api.docs.view ruxsati yoki superadmin bergan parol bilan ochiladi.'],
            ['title' => 'Qoralama: zaxira nusxa siyosati', 'author' => 'Ops', 'tags' => 'backup, ops, draft', 'published' => '',
                'body' => 'Kunlik pg_dump, haftalik to\'liq nusxa, 30 kun saqlash (muhokama qilinmoqda).'],
            ['title' => 'Qoralama: mobil ilova API', 'author' => 'Mobile', 'tags' => 'api, mobile, draft', 'published' => '',
                'body' => 'Shaxsiy tokenlar va OAuth2 client credentials orqali.'],
            ['title' => 'Monitoring va alertlar', 'author' => 'Ops', 'tags' => 'monitoring, ops', 'published' => '1',
                'body' => 'Prometheus metrikalari /metrics manzilida, health — /health/ready.'],
        ];
    }

    private static function pageviews(): array
    {
        mt_srand(20261106);
        $paths = ['/', '/projects', '/blog', '/login', '/profile', '/api/docs', '/projects/new', '/notifications'];
        $countries = ['UZ', 'UZ', 'UZ', 'UZ', 'KZ', 'RU', 'US', 'DE', 'TR'];
        $devices = ['desktop', 'desktop', 'mobile', 'mobile', 'mobile', 'tablet'];
        $refs = ['', '', 'https://google.com', 'https://t.me', 'https://github.com'];
        $out = [];
        for ($i = 0; $i < 240; $i++) {
            $out[] = [
                'viewed_at' => gmdate('Y-m-d H:i:s', time() - mt_rand(0, 14 * 86400)),
                'path' => $paths[mt_rand(0, count($paths) - 1)],
                'country' => $countries[mt_rand(0, count($countries) - 1)],
                'device' => $devices[mt_rand(0, count($devices) - 1)],
                'duration_ms' => (string) mt_rand(300, 90000),
                'referrer' => $refs[mt_rand(0, count($refs) - 1)],
            ];
        }
        mt_srand();
        return $out;
    }
}
