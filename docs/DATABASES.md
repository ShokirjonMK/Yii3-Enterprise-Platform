# Multiple databases (any engine, at the same time)

The platform works with any number of databases simultaneously, with different engines mixed freely. A typical
setup is three PostgreSQL servers, MongoDB, ClickHouse and MySQL.

| Engine | Driver / URL scheme | Used in code as | Runtime |
|---|---|---|---|
| PostgreSQL | `pgsql`, `postgres://` | `$dbs->sql('name')` (yiisoft/db `ConnectionInterface`) | ext-pdo_pgsql, yiisoft/db-pgsql |
| MySQL / MariaDB | `mysql://`, `mariadb://` | `$dbs->sql('name')` | ext-pdo_mysql, yiisoft/db-mysql |
| SQLite | `sqlite:///abs/path.sqlite` | `$dbs->sql('name')` | ext-pdo_sqlite, yiisoft/db-sqlite |
| SQL Server | `sqlsrv://`, `mssql://` | `$dbs->sql('name')` | ext-pdo_sqlsrv (+ Microsoft ODBC 18 driver), yiisoft/db-mssql |
| Oracle | `oci://`, `oracle://` (path = service name) | `$dbs->sql('name')` | ext-pdo_oci (+ Instant Client), yiisoft/db-oracle |
| MongoDB | `mongodb://`, `mongodb+srv://` | `$dbs->mongo('name')` (`MongoDB\Database`) | ext-mongodb, mongodb/mongodb |
| ClickHouse | `clickhouse://` (HTTP 8123), `clickhouses://` (HTTPS) | `$dbs->clickhouse('name')` (`ClickHouseClient`) | none (HTTP interface) |

All extensions are part of the `yep-php` image (`infra/docker/php/Dockerfile`).

**Admin → Databases** shows whether each driver is usable in the running PHP. Do not run SQL Server or Oracle in
production without testing them against your own server (see *Verification* below).

## 1. Declaring connections

### In `.env` (versioned with the deployment, read-only in the panel)

```dotenv
DB_CONNECTIONS=analytics,archive,docs,events,shop
DB_CONN_ANALYTICS_URL=postgres://report:${ANALYTICS_PASSWORD}@pg-analytics:5432/analytics?sslmode=require
DB_CONN_ANALYTICS_DESCRIPTION=Reports (second PostgreSQL)
DB_CONN_ARCHIVE_URL=postgres://app:${ARCHIVE_PASSWORD}@pg-archive:5432/archive?readonly=1
DB_CONN_DOCS_URL=mongodb://app:${MONGO_PASSWORD}@mongo:27017/docs?authSource=admin&replicaSet=rs0
DB_CONN_EVENTS_URL=clickhouse://app:${CLICKHOUSE_PASSWORD}@clickhouse:8123/events
DB_CONN_SHOP_URL=mysql://app:${MARIADB_PASSWORD}@mariadb:3306/shop?charset=utf8mb4
```

- **Placeholders.** `${VAR}` is replaced by that variable, URL-encoded, so a password is written once. Define the
  variable *before* the URL. A password typed directly into a URL must be percent-encoded.
- **Query parameters** become driver options:
  - `sslmode`, `timeout` (seconds), `statementTimeoutMs`;
  - `charset`, `tls`, `ca`;
  - `authSource`, `replicaSet`, `readPreference`;
  - `service` (Oracle), `encrypt` and `trustServerCertificate` (SQL Server).
- **`readonly=1`** makes the connection read-only. The server enforces it where the engine allows:

  | Engine | How read-only is enforced |
  |---|---|
  | PostgreSQL | `default_transaction_read_only` |
  | MySQL | `SESSION TRANSACTION READ ONLY` |
  | SQLite | `query_only` |
  | ClickHouse | `readonly=2` |
  | MongoDB | `secondaryPreferred` reads |
  | SQL Server / Oracle | Use a read-only login |

- **The name `platform`** is reserved for the platform database itself (`DB_*`).

### In the panel (superadmin)

Use **Admin → Databases → New connection**:
- choose the type, then fill in host, port, database, user, password and options (JSON);
- click **Test connection** to check it before saving;
- save; the password is encrypted (Encrypter, purpose `database-connection`) and never shown again.

The same page lets you edit, enable/disable and delete a connection. Every change requires step-up confirmation and
is audited (`database.connection.*`).

SQLite files created from the panel are stored in `runtime/databases/`.

A `.env` connection always wins over a panel connection with the same name.

## 2. Using connections in code

Inject `App\Platform\Database\Connections\DatabaseManager`:

```php
use App\Platform\Database\Connections\DatabaseManager;
use Yiisoft\Db\Query\Query;

final class ReportRepository
{
    public function __construct(private readonly DatabaseManager $dbs) {}

    public function salesByDay(string $from): array
    {
        // second PostgreSQL — the normal yiisoft/db Query builder
        return (new Query($this->dbs->sql('analytics')))
            ->select(['day', 'total' => 'SUM(amount)'])->from('sales')
            ->where(['>=', 'day', $from])->groupBy('day')->all();
    }

    public function archive(array $order): void
    {
        $this->dbs->sql('archive')->transaction(fn($db) => $db->createCommand()->insert('orders', $order)->execute());
    }

    public function attachments(string $orderId): array
    {
        // MongoDB
        return $this->dbs->mongo('docs')->selectCollection('attachments')->find(['order_id' => $orderId])->toArray();
    }

    public function track(array $events): void
    {
        // ClickHouse: batch insert (JSONEachRow) and server-side parameters {name:Type}
        $this->dbs->clickhouse('events')->insert('page_views', $events);
    }

    public function topPages(string $day): array
    {
        return $this->dbs->clickhouse('events')->select(
            'SELECT path, count() AS views FROM page_views WHERE day = {d:Date} GROUP BY path ORDER BY views DESC LIMIT 20',
            ['d' => $day],
        );
    }
}
```

- **One connection per name.** Each named connection opens lazily on first use and is reused for the rest of the
  request or worker loop. `$dbs->sql('platform')` is the platform's own primary connection.
- **Disabled connections.** A disabled connection throws `ConflictException('connection_disabled')`. So does calling
  `$dbs->mongo()` on an SQL connection.
- **After an edit.** Long-running workers pick up panel edits within 30 seconds. Call `$dbs->forget()` to drop cached
  clients immediately.
- **No cross-engine transactions.** A transaction covers one connection. For changes that span several databases, use
  the outbox and an idempotent consumer, as with Kafka events.

## 3. Admin → Databases

| Permission | Who has it | What it allows |
|---|---|---|
| `databases.view` | superadmin, admin, devops_sre | List of all connections with live status (version, latency); tables/collections with rows and sizes; driver availability |
| `databases.manage` | superadmin | Add, edit, enable/disable and delete panel connections (step-up) |
| `databases.query` | superadmin | Query console, **read mode** (step-up) |
| `databases.write` | superadmin | Console **write mode**. Never offered for read-only connections |

`databases.manage`, `databases.query` and `databases.write` are *privileged* permissions: only a superadmin can grant
them to a role.

**Read mode is enforced twice:**
1. The statement must be a single SELECT, WITH, SHOW, EXPLAIN, DESCRIBE or VALUES (MongoDB: find, aggregate without
   `$out`/`$merge`, or count).
2. It runs in a server-side read-only transaction that is always rolled back:

| Engine | Read-only mechanism |
|---|---|
| PostgreSQL | `SET TRANSACTION READ ONLY` |
| MySQL | `START TRANSACTION READ ONLY` |
| SQLite | `PRAGMA query_only` |
| ClickHouse | `readonly=2` |

Results are limited to 1000 rows. Every run is audited (`database.query.read` / `database.query.write`) with the
statement text, row count, duration and error.

## 4. Console

```bash
./yii db:connections          # list + test every connection (exit 1 if an enabled one fails)
./yii db:connections docs     # one connection
```

## 5. Development containers

`./scripts/platform.sh up databases` starts the profile `databases` from `compose.yaml`:

| Service | Engine | Connection name |
|---|---|---|
| `postgres-analytics` | PostgreSQL 17 | `analytics` |
| `postgres-archive` | PostgreSQL 17 | `archive` |
| `mongo` | MongoDB 8.0 | `docs` |
| `clickhouse` | ClickHouse 25.3 | `events` |
| `mariadb` | MariaDB 11.4 | `shop` |

The connections are pre-declared in `.env.example`, and their passwords are generated by `./scripts/platform.sh init`.

## 6. Reference module: Multi-DB demo (`src/Modules/MultiDb`)

This module shows a working CRUD over every extra connection. Each dataset is a different engine and a different shape:

| Dataset | Connection → engine | Table / collection | Engine specifics shown |
|---|---|---|---|
| `products` | `shop` → MariaDB (MySQL driver) | `products` | `AUTO_INCREMENT` id, `DECIMAL`, `TINYINT(1)` flag, `UNIQUE (sku)` → field error "SKU already exists." |
| `kpis` | `analytics` → PostgreSQL | `kpi_metrics` | uuid id, `UNIQUE (metric, period)`, statistics with `DISTINCT ON` |
| `orders` | `archive` → PostgreSQL | `archived_orders` | `CHECK` constraints, `GROUP BY status, currency` |
| `articles` | `docs` → MongoDB | `articles` | ObjectId, native tags array, BSON dates, regex search, `$unwind`/`$group` aggregation |
| `pageviews` | `events` → ClickHouse | `page_views` | MergeTree, batch `JSONEachRow` insert, `ALTER … UPDATE/DELETE` with `mutations_sync=1`, typed `{p:Type}` parameters |

The same `DatasetService` (validation by `Field` types, then the engine store `SqlDatasetStore` / `MongoDatasetStore` /
`ClickHouseDatasetStore`, then an audit row in the platform database) is used by all four interfaces:

| Interface | Where | Permissions |
|---|---|---|
| Admin | **Business modules → Multi-DB demo** (`/admin/multidb`): overview with row counts and engine statistics, *Install* button, table with search, filters, sort and paging, 422 forms, delete | `multidb.view/create/update/delete/install` |
| Web | `/multidb` (menu **Data sources**): tiles, card grid, detail, create/edit/delete with permission | same |
| REST API | `GET/POST /api/v1/multidb/{dataset}`, `GET/PATCH/DELETE /api/v1/multidb/{dataset}/{id}`, `GET /api/v1/multidb/{dataset}/stats`, `GET /api/v1/multidb` (catalogue with field definitions); in Swagger under *Multi-DB demo* | scopes `multidb.read` / `multidb.write` |
| Console | `./yii multidb:install [dataset] [--no-seed]`, `./yii multidb:crud <dataset> list\|show\|create\|update\|delete\|stats [id] --set field=value --q= --filter=k=v --sort=-x --json` | — |

```bash
./scripts/platform.sh up databases
docker compose exec -T app php yii migrate:up && docker compose exec -T app php yii rbac:sync
docker compose exec -T app php yii multidb:install        # tables + 12 / 30 / 15 / 8 / 240 sample rows
docker compose exec -T app php yii multidb:crud products list --q=pro --sort=-price
```

The platform migrator manages only the platform database. The demo tables in the other databases are created
idempotently by `multidb:install` (`DemoSchema`). For your own module, put such DDL in a versioned install command,
or use the target database's own migration tool. Roles: `business_admin` (and `admin`) get everything; `developer`
gets view/create/update/delete; `user` gets view only.

## Verification

| What | Status |
|---|---|
| PostgreSQL ×3, MongoDB, ClickHouse, MariaDB, SQLite | TESTED: `tests/Integration/MultiDatabaseTest.php`, `tests/E2E/Admin/DatabasesAdminTest.php` against the dev containers |
| Multi-DB demo CRUD on all five engines (service, Admin, Web, API, console) | TESTED: `tests/Integration/MultiDbDatasetsTest.php` (12), `tests/Api/ApiMultiDbTest.php` (9), `tests/E2E/MultiDbUiTest.php` (5) |
| SQL Server, Oracle | IMPLEMENTED, extensions installed; UNVERIFIED, no server in the dev stack. Use **Test connection** against your server |
