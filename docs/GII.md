# Enterprise Gii

Enterprise Gii generates **fully integrated business modules** that follow the structure of the reference
module `src/Modules/Projects` (Domain / Application / Infrastructure, actions and views in separate folders,
one config file per concern). After `--apply`, `./yii migrate:up` and `./yii rbac:sync` the module works in
Admin, Web and the REST API, including its tests.

It is a dev-only tool built **on top of the official `yiisoft/yii-gii`** package (dev dependency).

## How it builds on yiisoft/yii-gii

| yii-gii piece | Used for |
|---|---|
| `Generator\AbstractGenerator` | base of every generator (`App\Platform\Gii\Generator\AbstractEnterpriseGenerator`): `generate()` validates the command, `render()` renders the PHP templates |
| `Generator\AbstractGeneratorCommand` / `GeneratorCommandInterface` | `ModuleCommand` (module name, field spec, root, features) validated by yiisoft/validator attributes (`Regex`, `Required`, yii-gii `TemplateRule`) |
| `Gii` / `GiiInterface` | registry holding the 14 generators + the composite one (`EnterpriseGii::gii()`) |
| `Component\CodeFile\CodeFile` | every generated file: state *not exist / same / different*, `diff()` (unified diff via phpspec/php-diff), `save()` |
| `Component\CodeFile\CodeFileWriter` | writes the files with per-file answers (`save` / `skip`) |

Custom code (justified): templates for this platform's conventions, the field-spec parser, path safety and the
overwrite policy (yii-gii's console commands overwrite on `--overwrite` for *all* files; we require per-file
consent). The yii-gii JSON API (`/gii/api`, CORS `*`) stays **disabled** (`yiisoft/yii-gii.enabled = false` in
`config/common/params.php`); the generators are reachable only through the console and the authenticated admin page.

Code: `src/Platform/Gii/` (`EnterpriseGii`, `PathGuard`, `GiiPlan`, `PlannedFile`, `Spec/*`, `Generator/*`,
`Console/GiiModuleCommand`), templates in `src/Platform/Gii/templates/<generator>/` (shared ones in `_shared/`),
DI `config/common/di/gii.php`, console `config/console/commands/gii.php`, admin `config/routes/admin/22a-gii.php`,
`src/Admin/Action/DevTools/GiiController.php`, `resources/views/admin/gii/index.php`.

## Console

```bash
# dry run (default): prints every file with its status, writes nothing
docker compose exec -T app php yii gii:module Contracts \
  --fields='title:string:required:max=255,amount:decimal:min=0,status:enum(draft|active|closed),due_on:date,owner_id:user'

# subset (dependencies are added automatically), show diffs of changed files, print one file
… gii:module Contracts --fields='…' --generators=crud,api --diff --show=src/Modules/Contracts/Domain/Contract.php

# write
… gii:module Contracts --fields='…' --apply
# replace protected files only after reviewing their diff
… gii:module Contracts --fields='…' --apply --overwrite=src/Modules/Contracts/Domain/Contract.php,config/common/jobs/contracts.php

docker compose exec -T app php yii migrate:up && docker compose exec -T app php yii rbac:sync
docker compose exec -T app php yii kafka:provision      # renders the topic script (contracts.events)
```

On Git Bash prefix with `MSYS_NO_PATHCONV=1`. Status column:

| Status | Meaning |
|---|---|
| `new` | file does not exist → created on `--apply` |
| `unchanged` | identical file exists → skipped (yii-gii `PRESENT_SAME`) |
| `skip` | file exists with different content → **protected**, never written unless listed in `--overwrite` |
| `overwrite` | file differs and its path is in `--overwrite` → replaced on `--apply` |

Exit codes: `0` ok, `65` invalid input (all errors listed), `74` write error. The command refuses to run with
`APP_ENV=prod` and is not registered when yii-gii is not installed.

## Admin UI

Admin → Developer Tools → **Enterprise Gii** (`/admin/devtools/gii`, route `admin.devtools.gii`):
module name, field spec, generator checkboxes → **Preview** (dry run: every file with status, content preview,
colored unified diff for existing files, an "Overwrite this existing file" checkbox for protected ones) →
**Apply** (requires the "I reviewed the preview" checkbox, a confirm dialog and step-up authentication
`RequireRecentAuth`; the plan is recomputed on submit). Every apply is audited (`devtools.gii.applied`).

Access: permission `devtools.use` (route middleware) and the page returns **404** when `APP_ENV=prod`,
when flag `devtools.gii` is off (`FeatureFlagService::isEnabled('devtools.gii')`, which also refuses in prod),
or when yii-gii is missing (`class_exists` guard — production images have no dev dependencies).

## Field spec

`name:type[:modifier]*`, fields separated by commas (or new lines), max 30 fields.

| Type | Column | Notes |
|---|---|---|
| `string` | `varchar(max, default 255)` | whitespace collapsed; `required` adds `CHECK (btrim(x) <> '')` |
| `text` | `text` | `CHECK char_length <= max` (default 10000) |
| `email` | `varchar(254)` | lower-cased, `FILTER_VALIDATE_EMAIL` |
| `int` | `integer` | `min`/`max` → CHECK constraints |
| `decimal` | `numeric(14,2)` | accepts `1 234,5` → `1234.50`; `min`/`max` → CHECK |
| `bool` | `boolean NOT NULL DEFAULT false` | `default=true` |
| `date` | `date` | `YYYY-MM-DD` |
| `datetime` | `timestamptz` | any parseable value → ISO 8601 |
| `enum(a\|b\|c)` | `varchar(32) NOT NULL DEFAULT 'a'` + `CHECK IN (…)` | `default=b`; **`status:enum(…)` is the lifecycle** |
| `user` | `uuid REFERENCES users` | must be an active user of the same tenant; required ones default to the acting user |
| `uuid` | `uuid` | |

Modifiers: `required`, `unique` (partial unique index per tenant, `WHERE deleted_at IS NULL`), `index`,
`max=N`, `min=N`, `default=V`. Reserved names (managed columns such as `id`, `tenant_id`, `version`,
`created_at`, `deleted_at`, …) are rejected. The first `string`/`email` field is the record title.

Lifecycle: an enum named `status` gets a `<Entity>Status` state machine — initial = first option, transitions
forward to any later state, the last state is terminal and read-only (`409 invalid_transition` /
`409 <entity>_read_only`).

Module name: `^[A-Z][A-Za-z0-9]{2,40}$` (PascalCase plural). Names of platform sections/routes and the
reference module (`Projects`, `Users`, `Settings`, …) are reserved. Derived names for `Contracts`: entity
`Contract`, table/permission prefix/code `contracts`, URL slug `contracts`, flag `module.contracts`, topic
`contracts.events`, workflow `contract.lifecycle`.

## Generators

`--generators=all` (default; the composite **Enterprise Module** generator `module`) runs them in this order.

| id | Generator | Produces |
|---|---|---|
| `entity` | PostgreSQL Entity / Repository | `module.php` manifest, `Domain/<Entity>.php` (aggregate, events, soft delete), `Domain/<Entity>Status.php`, `Domain/<Entity>Rules.php`, `Infrastructure/<Entity>Repository.php` (tenant scope, sort whitelist, filters, search, optimistic lock, constraint → field errors), migration `M…<Module>` (uuid PK, `tenant_id`, `version`, CHECKs, partial indexes, `updated_at` trigger, flag `module.<code>`, `business_modules` row, config revision bump), `config/common/di/<code>.php` |
| `crud` | CRUD | `Application/<Entity>Service.php` (RBAC per use case, tenant isolation → 404, `stale_version` 409, audit + outbox in one transaction), plus rules + repository |
| `rbac` | RBAC | `config/common/permissions/<code>.php` (`view/create/update/delete`; `business_admin` all, `user`/`developer` view) |
| `event` | Kafka Event | `Domain/<Entity>Event.php` (topic, types, payload, summary), outbox usage in the service, `Infrastructure/<Entity>ActivityProjector.php` (idempotent consumer), `config/common/events/<code>.php` (group `<slug>-activity`), migration `…Events` (`<entity>_activity`, `kafka_topics` row) |
| `workflow` | Workflow | migration `…Workflow` (definition `<entity>.lifecycle` for `WorkflowEngine`, transitions `to_<state>`), `Application/<Entity>Workflow.php` (start + mirror; no-op when disabled; never breaks the business transaction) |
| `notification` | Notification | migration `…Notifications` (template `<entity>.status_changed` or `.created`, en/ru/uz × inapp/email/telegram, existing rows kept), `Application/<Entity>Notifier.php` (Notifier call site, category `<code>`) |
| `job` | Worker Job | `Infrastructure/PurgeDeleted<Module>Job.php` (`<code>.cleanup`: purge soft-deleted rows older than `retention_days`, batched, cancellable, audited), `config/common/jobs/<code>.php` |
| `report` | Report | `Infrastructure/Generate<Module>ReportJob.php` (`<code>.report.generate`: CSV with BOM, formula-injection safe → ObjectStorage `reports/<slug>/…`), migration `…Reports`, service `requestReport/report/recentReports`, admin report page + download |
| `scheduler` | Scheduler | migration `…Schedule` (`schedules` row `<code>.cleanup`, `40 2 * * *`, payload `{"retention_days":90}`) |
| `connector` | Integration Connector | `Infrastructure/<Module>Connector.php` via `IntegrationService::call()` → platform `HttpClient` (SSRF guard, timeout, retries with backoff, circuit breaker, logs), migration `…Integration` (integration row, disabled = *Not configured*, 5 s / 3 retries / 300 ms backoff) |
| `admin` | Admin UI | `config/routes/admin/<NN>-<slug>.php` (flag + `RequirePermission`, middleware before action), `src/Admin/Action/<Module>/<Module>Controller.php`, views `index` (KPI per status, HTMX filters/search/sort, pager), `form` + `_fields` (422 errors per field), `view` (transitions, delete with `data-confirm`, activity), `report` |
| `web` | Web UI | `config/routes/web/<slug>.php`, `src/Web/Action/<Module>/<Module>Controller.php`, views `index/form/view/_fields` |
| `api` | REST API | `config/routes/api-v1/<NN>-<slug>.php` (Bearer, flag, `RequireApiPermission::for(perm, '<code>.read'/'<code>.write')`), controller with OpenAPI attributes (`<Entity>` / `<Entity>Input` schemas), ETag/If-Match (428/409), 201 + Location, 204 delete |
| `tests` | Tests | `tests/Unit/<Module>DomainTest.php`, `tests/Integration/<Module>ServiceTest.php`, `tests/Api/Api<Module>Test.php`, `tests/E2E/<Module>UiTest.php` — covering exactly the generated features |

Dependencies are resolved automatically (`api` → `entity, crud, rbac, api`; `scheduler` → `entity, job`).
Templates switch features on when the generator runs **or** its code already exists (e.g. running `crud`
alone in a module that has `event` keeps the outbox calls). `workflow` is left out when there is no `status`
field. Files shared by generators (rules, repository, jobs config) are rendered from one template.

Every generated PHP file starts with the header docblock
`Generated by Yii3 Enterprise Platform · Enterprise Gii — author ShokirjonMK`.

## Safety

- **Dry run by default**; `--apply` / the confirmed admin form writes.
- **Overwrite protection**: different existing files are `skip` unless their relative path is explicitly
  listed (`--overwrite=a,b` / ticked checkbox); identical files are `unchanged`; files that appear between
  preview and apply are not overwritten. Unknown `--overwrite` paths are an error.
- **Path safety** (`PathGuard`): relative paths only, no `..`/absolute/drive paths, charset `[A-Za-z0-9_./-]`,
  `.php` only, only inside module-owned locations (`src/Modules/`, `src/Admin|Web/Action/`, `src/Api/V1/Action/`,
  `resources/views/admin|web/`, `config/routes/{admin,web,api-v1}/`, `config/common/{permissions,jobs,events,di}/`,
  `migrations/`, `tests/{Unit,Integration,Api,E2E}/`), never `src/Modules/Projects/`, `src/Admin/Action/DevTools/`,
  API auth/docs; the real path of the nearest existing directory must be inside the real root; symlinks refused.
  Checked when planning and again right before writing.
- **Idempotent**: migration timestamps and route-file numbers of an existing module are re-used, output contains no
  dates → a second run reports everything `unchanged`.
- Not available in production (console + admin), not registered without yii-gii, yii-gii web API disabled.

## Sample: the generated Contracts module

The repository contains a second working sample module generated with:

```bash
./yii gii:module Contracts --fields='title:string:required:max=255,amount:decimal:min=0,status:enum(draft|active|closed),due_on:date,owner_id:user' --apply
./yii migrate:up && ./yii rbac:sync
```

Lifecycle `draft → active → closed` (forward-only, `closed` read-only). Admin `/admin/contracts`, Web
`/contracts`, API `/api/v1/contracts`, flag `module.contracts`, topic `contracts.events` (group
`contracts-activity`), jobs `contracts.cleanup` (scheduled nightly) and `contracts.report.generate`,
integration `contracts` (Not configured), workflow `contract.lifecycle`, notification `contract.status_changed`.
It is **not** hand-edited: re-running the command above reports all 43 files `unchanged` (asserted by
`tests/E2E/GiiGeneratedModuleTest.php`). Files: `src/Modules/Contracts/**`, `src/{Admin,Web}/Action/Contracts/`,
`src/Api/V1/Action/Contracts/`, `resources/views/{admin,web}/contracts/`, `config/routes/admin/23b-contracts.php`,
`config/routes/web/contracts.php`, `config/routes/api-v1/31-contracts.php`,
`config/common/{permissions,jobs,events,di}/contracts.php`, `migrations/M261104110237Contracts*.php` …
`M261104110243ContractsSchedule.php`, `tests/{Unit/ContractsDomainTest,Integration/ContractsServiceTest,Api/ApiContractsTest,E2E/ContractsUiTest}.php`.

## Tests

```bash
docker compose exec -T -e APP_ENV=test app php vendor/bin/phpunit --testsuite integration --filter Gii   # generator proof (temp root)
docker compose exec -T -e APP_ENV=test app php vendor/bin/phpunit tests/Unit/ContractsDomainTest.php tests/Integration/ContractsServiceTest.php
docker compose exec -T app php vendor/bin/phpunit tests/Api/ApiContractsTest.php tests/E2E/ContractsUiTest.php tests/E2E/GiiGeneratedModuleTest.php
```

`tests/Integration/GiiGeneratorTest.php` generates `Contracts` (and a subset module) into a **temporary root**
(never the real `src/`), asserts the file list, runs `php -l` on every generated file, asserts the header,
that a dry run writes nothing, idempotency, overwrite protection + explicit overwrite, dependency resolution,
input validation and path safety (traversal, forbidden locations, symlink escape).

Known limits: the OAuth scopes `<code>.read`/`<code>.write` are not in `AuthController::SCOPES` (platform core),
so tokens must use `*` (the default) until they are added; the Kafka topic is registered in `kafka_topics` and
created by the script `./yii kafka:provision` renders.

— Author: ShokirjonMK
