# Business modules

Business modules live in `src/Modules/<Name>/{Domain,Application,Infrastructure}` and plug into the platform
only through configuration files (routes, permissions, jobs, consumer groups, DI) — they never edit platform
core code. The **Projects** module is the reference implementation: copy its structure when building a new
module.

## Projects — the reference business module

| Concern | Where |
|---|---|
| Manifest | `src/Modules/Projects/module.php` (`code`, `name`, `version`, `flag` = `module.projects`, `admin_route` = `admin.projects`) |
| Schema | `migrations/M261104050001Projects.php` — `projects`, `project_members`, `project_activity` (read model), `project_reports` |
| Domain | `Domain/Project.php` (aggregate), `ProjectStatus.php` (state machine), `ProjectPriority.php`, `ProjectRules.php` (validation), `ProjectEvent.php` |
| Use cases | `Application/ProjectService.php`, `Application/ProjectWorkflow.php` (approval bridge), `ArchiveOutcome.php` |
| Persistence | `Infrastructure/ProjectRepository.php` |
| Background job | `Infrastructure/GenerateProjectsReportJob.php` → `config/common/jobs/projects.php` (`projects.report.generate`) |
| Kafka consumer | `Infrastructure/ProjectActivityProjector.php` → `config/common/events/projects.php` (group `projects-audit`, topic `projects.events`) |
| RBAC | `config/common/permissions/projects.php` → `./yii rbac:sync` |
| Admin UI | `config/routes/admin/23a-projects.php`, `src/Admin/Action/Projects/`, `resources/views/admin/projects/` |
| Web UI | `config/routes/web/projects.php`, `src/Web/Action/Projects/`, `resources/views/web/projects/` |
| REST API | `config/routes/api-v1/30-projects.php`, `src/Api/V1/Action/Projects/ProjectsController.php` (OpenAPI attributes) |
| Tests | `tests/Unit/ProjectsDomainTest.php`, `tests/Integration/ProjectsServiceTest.php`, `tests/Api/ApiProjectsTest.php`, `tests/E2E/ProjectsUiTest.php` |

### Data model

`projects`: uuid `id`, `tenant_id`, `code` (unique per tenant, `^[A-Z0-9][A-Z0-9_-]{1,31}$`), `name`,
`description`, `status`, `priority`, `owner_id`, `budget numeric(14,2)`, `starts_on`, `due_on`
(`CHECK due_on >= starts_on`), `tags jsonb` (array), `version` (optimistic lock), `created_by`, timestamps,
`archived_at`, generated `search tsvector` (GIN) and a `pg_trgm` index on `lower(name)` (created inside a `DO`
block only when the extension is available). `project_members (project_id, user_id, role)` with roles
owner/manager/member/viewer.

### Lifecycle

```
draft ──► active ◄──► on_hold
  │         │            │
  │         └──► completed ◄┘
  └──────► archived ◄──── completed      (archived = terminal, read-only)
```

The domain state machine (`ProjectStatus`) is authoritative. If the platform workflow definition
`project.lifecycle` (`App\Platform\Workflow\WorkflowEngine`) exists and is enabled, `ProjectWorkflow` mirrors every
transition onto a workflow instance and **archiving a completed project requires an approval**
(`approvals.decide`, four-eyes, 7-day expiry). The project is archived when an approved instance is next
loaded (`ProjectService::get()` finalises it, actor = approver). Archiving a draft never needs approval.

### Rules enforced by `ProjectService`

1. RBAC per use case: `projects.view | create | update | delete | approve`; route middleware repeats the
   check (defence in depth).
2. Tenant isolation: without `tenants.cross` only the actor's tenant is visible; foreign ids → `NotFoundException`.
3. Ownership: mutations require the owner or `projects.manage_all`; only `manage_all` may assign another owner.
4. Activating a draft requires `projects.approve`; archiving requires `projects.delete`.
5. Optimistic locking on `version` → `409 stale_version`.
6. One DB transaction per change containing the row, `AuditLogger::record(...)`, the `Outbox::record('projects.events',
   'project.created|updated|status_changed|archived', 'project', $id, $payload)` event and notifications.
7. Status changes notify the owner and members (template `project.status_changed`, channels in-app + e-mail).

### Roles (`./yii rbac:sync`)

| Role | Projects permissions |
|---|---|
| `business_admin` | all six (`view`, `create`, `update`, `delete`, `approve`, `manage_all`) |
| `user`, `developer` | `projects.view` (and every role that inherits `user`, e.g. `auditor`) |
| `superadmin` | everything (implicit) |

### Events and the consumer side

The relay publishes outbox rows to Kafka topic `projects.events` (key = project id → per-project ordering).
Consumer group `projects-audit` (`./yii kafka:consume --group=projects-audit`) runs
`ProjectActivityProjector`, which maintains `project_activity` — the timeline shown in Admin/Web.
Idempotency: `KafkaConsumerRunner` stores the message id in `inbox` in the same transaction as the handler,
and `project_activity.event_id` is UNIQUE (`ON CONFLICT DO NOTHING`). Until the consumer has caught up the UI
falls back to the audit trail.

### Background report

`ProjectService::requestReport()` inserts a `project_reports` row and enqueues `projects.report.generate` in the
same transaction (idempotency key per report). The handler streams the tenant's projects into CSV (UTF-8 BOM,
formula-injection safe), writes it to object storage at `reports/projects/<tenant>/<date>-<report>.csv`, records
size/checksum and reports progress. Admin shows the status page (auto-refresh) and a download link; the API
returns `202` + `Location: /api/v1/projects/reports/{id}`.

## How to build a module (checklist)

1. **Flag** — add a feature flag `module.<code>` (seeder or Admin → Feature flags) and gate every route group
   with `App\Api\V1\Middleware\RequireFeature::for('module.<code>')`.
2. **Manifest** — `src/Modules/<Name>/module.php` returning `code`, `name`, `version`, `flag`, `admin_route`.
3. **Migration** — `migrations/M<yymmddhhmmss><Name>.php` using `SqlScript::run()`; uuid PKs, `tenant_id`,
   `version`, `updated_at` trigger `platform_touch_updated_at()`, CHECK constraints for invariants.
4. **Domain** — entities/value objects with invariants and a state machine; no I/O; raise domain events.
5. **Permissions** — `config/common/permissions/<module>.php` (extend existing roles; do not redefine
   descriptions), then `./yii rbac:sync`.
6. **Application service** — assert permission → load with tenant check (foreign → 404) → domain call →
   one transaction with repository write + `AuditLogger` + `Outbox::record()`; optimistic locking.
7. **Jobs / consumers** — `config/common/jobs/<module>.php` (`'type' => ['handler' => …, 'queue' => …]`) and
   `config/common/events/<module>.php` (`'group' => ['topics' => […], 'handlers' => [eventType => Handler]]`).
8. **Admin UI** — `config/routes/admin/NN-<module>.php` (`->middleware()` before `->action()`), thin
   controllers using `Responder`, views in `resources/views/admin/<module>/`, CSS only in your block of
   `assets/admin/app.css`, no inline scripts/styles (strict CSP), HTMX for list navigation.
9. **Web UI** — `config/routes/web/<module>.php` (inside the authenticated group) + `resources/views/web/<module>/`.
10. **REST API** — `config/routes/api-v1/NN-<module>.php` with `BearerAuthentication`,
    `RequireApiPermission::for(permission, scope)`, controllers in `src/Api/V1/Action/<Resource>/` annotated
    with `OpenApi\Attributes`; regenerate `./yii api:openapi`.
11. **DI** — `config/common/di/<module>.php` only for non-autowirable bindings.
12. **Tests** — unit (domain), integration (service: outbox in tx, tenant isolation, locking, jobs via `Worker`,
    consumers via `KafkaConsumerRunner::process`), API and E2E over HTTP.
13. After adding routes on a running stack, clear the router cache (FastRoute dispatch data is cached under
    the PSR-16 key `routes-cache`).

— Author: ShokirjonMK
