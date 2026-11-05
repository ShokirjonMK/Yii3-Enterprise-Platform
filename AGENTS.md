# AGENTS.md — engineering conventions for Yii3 Enterprise Platform

Read this before changing code. It is the contract between every contributor (human or AI agent).

## 1. Ground rules

- **Real implementations only.** No TODO stubs, fake responses, placeholder pages or mocked dashboards. If an external
  system is unavailable, implement the real adapter + health check, show **"Not configured"** in the UI and mark the
  item `UNVERIFIED` in `docs/IMPLEMENTATION_STATUS.md` with what is required.
- **Never claim PASS without running the test.** Record actual numbers.
- **Reuse first.** Official `yiisoft/*` packages and mature libraries before custom code. Justify custom code in docs.
- **Do not run `composer require/update`** unless you own dependency management; ask the lead (or note it in your report).
- **Security is server-side.** Every action checks permissions (route middleware + application service). Default deny.
- **No secrets in code, logs, audit or fixtures.** Use `Redactor`, `Encrypter`, settings of type `secret`.
- **No git commits/pushes** unless the user explicitly asks.

## 2. Stack & runtime

PHP 8.3 (Docker `yep-php:dev`), Yii3 (`yiisoft/app` 1.4 structure), PostgreSQL 17, Redis 7.4 (Predis), Kafka 3.9 KRaft
(ext-rdkafka), SeaweedFS (S3 API) in dev, Mailpit, nginx + PHP-FPM.

```bash
./scripts/platform.sh up                     # stack (http://localhost:8090 — HTTP_PORT in .env)
docker compose exec app php yii <command>    # console
docker compose exec -T -e APP_ENV=test app php vendor/bin/phpunit --testsuite unit,integration
docker compose exec -T app php vendor/bin/phpunit --testsuite api,e2e,security     # against running stack
```
On Windows/Git-Bash prefix docker commands with `MSYS_NO_PATHCONV=1`.

## 3. Layout (where things go)

Rule: **a folder is a feature** — controllers, `views/` and `routes.php` live together. Full guide with the tree,
aliases and a Yii2 → Yii3 mapping: [docs/PROJECT_STRUCTURE.md](docs/PROJECT_STRUCTURE.md).

| What | Where |
|---|---|
| Admin section (Control Center) | `src/Admin/<Section>/` — `<Name>Controller.php`, `routes.php` (prefix `/admin` is added), `views/` |
| End-user area | `src/Web/<Area>/` — controllers, `routes.php`, `views/` (Home, Account, Auth, Notifications, System) |
| REST API v1 resource | `src/Api/V1/<Resource>/` — controller (OpenAPI attributes), `routes.php` |
| **Business module** | `src/Modules/<Name>/` — `module.php`, `Domain/`, `Application/`, `Infrastructure/`, `Admin/`, `Web/`, `Api/` (each: controller + `routes.php` + `views/`), `config/{di,permissions,jobs,events,commands}.php`, `migrations/` — generate it with Enterprise Gii |
| Business-agnostic platform services | `src/Platform/<Area>/` |
| Shared views | `resources/views/{layouts,components,errors}/` only |
| Area route entry points | `config/routes/{admin,web,api-v1,auth,system,webhooks}.php` — middleware + discovery, no feature routes |
| Platform DI / permissions / jobs / consumer groups | `config/common/di/<area>.php`, `config/common/permissions/platform.php`, `config/common/jobs/*.php`, `config/common/events.php` |
| Notification channel adapters | `config/common/notification-channels/<name>.php` |
| Console commands | platform: `config/console/commands/<area>.php`; module: `src/Modules/<Name>/config/commands.php` |
| Migrations | platform: `migrations/`; module: `src/Modules/<Name>/migrations/` (non-namespaced `M<yymmddhhmmss><Name>`; `SqlScript::run($b, <<<'SQL' … SQL)`) |
| Tests | `tests/{Unit,Integration,Api,E2E,Security}` |

Discovery is automatic (globs in `config/routes/*.php`, `config/common/di/modules.php`, `config/common/{jobs,events}.php`,
`RbacCatalog`, migration paths in `params.php`, OpenAPI dirs in `config/common/di/api.php`) — never register a module
in shared files. Namespace = folder (`src/Admin/Kafka` → `App\Admin\Kafka`, `src/Modules/Blog/Admin` → `App\Modules\Blog\Admin`).

## 4. Patterns

### Admin section skeleton
```php
// src/Admin/Integrations/routes.php
return [
    Group::create('/integrations')->middleware(RequirePermission::for('integrations.view'))->routes(
        Route::get('')->action([IntegrationsController::class, 'index'])->name('admin.integrations'),
        Route::post('/{id}')->middleware(RequirePermission::for('integrations.manage'))
             ->action([IntegrationsController::class, 'update'])->name('admin.integrations.update'),
    ),
];
```
- `->middleware()` **before** `->action()` (router requirement).
- Destructive/critical POSTs additionally `->middleware(RequireRecentAuth::class)` (step-up password+MFA).
- Controllers are thin: parse input → call application service → `Responder`.
- `Responder::render('@admin/<Section>/views/index', $params)` (layout `admin`; module: `'@modules/<Name>/Admin/views/index'`), `->fragment()` for HTMX fragments,
  `->redirect('route.name')` (HTMX-aware), `->success()/->error()` flashes, `->json()`, `->download()`.
- Domain exceptions (`ValidationException`, `NotFoundException`, `AccessDeniedException`, `ConflictException`,
  `TooManyRequestsException`) are mapped to pages/JSON by `DomainErrorMiddleware`. For forms, catch
  `ValidationException` and re-render with `errors` (422).
- Audit every state change: `AuditLogger::record($action, $entityType, $entityId, $before, $after, $meta, category:, severity:)`.
  Inside a transaction the audit row commits atomically with the change.
- Tenant isolation: actors without `tenants.cross` only see their tenant; foreign objects → `NotFoundException` (IDOR).
- Current actor: inject `Yiisoft\User\CurrentUser`; `$currentUser->getIdentity()` is `App\Platform\Identity\Domain\Identity`
  (`->user` is `App\Platform\Identity\Domain\User`).

### Views / UI
- Every view gets `$h` (`ViewHelpers`): `e()` encode, `t()` translate+encode, `tr()` raw translate, `url()`, `urlOrNull()`,
  `icon('name')`, `badge($status)`, `datetime()`, `ago()`, `bytes()`, `json()`, `sortLink()`. Also `$can('perm')`,
  `$identity`, `$csrf`, `$isHtmx`, `$platform`.
- Set `$this->setTitle(...)` and `$this->setParameter('breadcrumbs', ['Label' => url, 'Current'])`.
- **Strict CSP:** no inline `<script>`/`style=""`/`on*=` attributes. Use CSS classes from `assets/admin/app.css`
  (`card`, `card__head`, `card__body`, `grid grid--2|3|4|sidebar`, `table`, `toolbar`, `btn btn--primary|danger|ghost|sm`,
  `badge`, `form form--2`, `field`, `field__error`, `tabs`, `kpi`, `dl`, `empty`, `alert`, `bar w-NN`, `timeline`, `tag`, `mono`).
- Forms: `<input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">`; destructive forms `data-confirm="…"`.
- HTMX: navigation/filters with `hx-get … hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"`
  (the admin layout returns only `<main id="main">` for HTMX requests). Auto-refresh tiles: `hx-trigger="every 30s"`.
  Pagination component: `$this->render('//components/pager', ['page' => $page, 'route' => …, 'query' => […]])`.
  Sibling partial: `$this->render('./_partial', [...])`.
- Lists: `Paginator::paginate($query, $page, $perPage, $sort, SORTABLE_WHITELIST, $default)` → `Page`.
- Icons available: see `public/static/icons.svg` (dashboard, users, key, shield, building, api, plug, bell, flow, clock,
  stream, database, bolt, box, server, rocket, activity, lock, archive, file, settings, code, grid, alert, search, plus,
  edit, trash, refresh, play, pause, x, check, menu, sun, moon, globe, logout, user, download, folder, flag, eye, info).
- Texts are English source strings passed through `$h->t()`; translations live in `resources/messages/{uz,ru}/app.php`.

### Platform services you can use
`AuditLogger`, `AccessService` (`can/assert/isSuperadmin/rolesOf`), `UserService`, `SettingsService` (`get/set`),
`FeatureFlagService` (`isEnabled`), `JobQueue` (`push($type, $payload, ['queue','delay','idempotency_key',…])`),
`Scheduler`, `Outbox` (`record($topic, $eventType, $aggregateType, $aggregateId, $payload)` — call inside the business
transaction), `KafkaAdmin`, `KafkaConsumerRunner::replay`, `Notifier` (`notify($userId, $templateCode, $params, $channels, $category)`),
`HealthChecker`, `Metrics` (`inc/observe/gauge`), `ObjectStorage` (Flysystem S3), `Encrypter`, `RedisRateLimiter`,
`LockInterface` (Redis) / `PostgresAdvisoryLock`, `DatabaseRouter` (`read()` replica / `primary()`), `RequestContext`.

## 5. Tests
- `tests/Integration/*Test.php` extend `IntegrationTestCase` (APP_ENV=test, DB `platform_test` auto-migrated+seeded).
- HTTP suites use `tests/Support/WebClient` (cookies, CSRF, HTMX headers) and `Fixtures::user([...roles], mfa: bool)`;
  call `Fixtures::resetLoginThrottle()` in `setUp()` and `Fixtures::cleanup()` in `tearDown()`.
- Admin login helper pattern: create a user with role + `mfa: true`, POST `/login`, POST `/login/mfa` with `Fixtures::totp()`.

## 6. Status vocabulary (docs/IMPLEMENTATION_STATUS.md)
`IMPLEMENTED` (code exists) · `TESTED` (automated tests pass) · `VERIFIED` (checked end-to-end in the running stack) ·
`BLOCKED` · `NOT IMPLEMENTED` · `NOT RUN`. Never write PASS for NOT RUN.
