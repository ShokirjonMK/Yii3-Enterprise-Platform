# Project structure

One rule explains almost everything:

> **A folder is a feature.** Its controllers, its `views/` and its `routes.php` sit together in one folder.
> To find, change, copy or delete a feature you open exactly one folder.

There are three kinds of feature folders, all following that rule:

| Kind | Folder | Example |
|---|---|---|
| Admin section (Control Center) | `src/Admin/<Section>/` | `src/Admin/Kafka/`, `src/Admin/Users/` |
| End-user area | `src/Web/<Area>/` | `src/Web/Account/`, `src/Web/Auth/` |
| API resource | `src/Api/V1/<Resource>/` | `src/Api/V1/Users/` |
| **Business module** (all three areas + its own logic, config and migrations) | `src/Modules/<Name>/` | `src/Modules/Blog/` |

Everything else is shared platform code (`src/Platform`, `src/Shared`) or shared resources (layouts, components,
translations).

## Tree

```
Yii3-Enterprise-Platform/
├── src/
│   ├── Admin/                        Control Center (/admin) — everything of the admin in one place
│   │   ├── Users/                    one section = one folder
│   │   │   ├── UsersController.php
│   │   │   ├── OrganizationsController.php
│   │   │   ├── routes.php            the section's routes (prefix /admin is added by config/routes/admin.php)
│   │   │   └── views/
│   │   │       ├── index.php  view.php  form.php  _tabs.php  _field.php
│   │   │       └── organizations/    sub-pages get a sub-folder
│   │   ├── Kafka/  Rbac/  Settings/  DevTools/  …   (24 sections, same shape)
│   │   ├── Menu/AdminMenu.php        the sidebar (order, groups, permissions)
│   │   └── Middleware/               admin-only middleware (network allow-list)
│   │
│   ├── Web/                          end-user site
│   │   ├── Home/       HomeController.php, routes.php, views/
│   │   ├── Account/    Profile, 2FA methods, appearance — routes.php, views/
│   │   ├── Auth/       login, 2FA, SSO, step-up — routes.php, views/, SecondFactor.php, WebAuthSession.php
│   │   ├── Notifications/  inbox + Web Push — routes.php, views/
│   │   ├── System/     health, metrics, theme CSS, 404 — routes.php
│   │   └── Middleware/ RequireLogin, RequirePermission, RequireRecentAuth, Locale, AuthContext
│   │
│   ├── Api/V1/                       REST API (/api/v1)
│   │   ├── Auth/  Users/  System/    resource = controller + routes.php (System: health, OpenAPI, /api/docs page)
│   │   ├── Http/  Middleware/  OpenApi/  Console/
│   │
│   ├── Modules/                      business modules — one module = one folder
│   │   └── Blog/
│   │       ├── module.php            manifest (code, name, version, flag module.blog, admin/web routes)
│   │       ├── Domain/               entity, status (state machine), rules, events   ← "models"
│   │       ├── Application/          use cases: BlogService, BlogWorkflow           ← business logic
│   │       ├── Infrastructure/       repository, jobs, Kafka projector, connector  ← I/O
│   │       ├── Admin/                BlogController.php, routes.php, views/
│   │       ├── Web/                  BlogController.php, routes.php, views/
│   │       ├── Api/                  BlogController.php (OpenAPI attributes), routes.php
│   │       ├── config/               di.php  permissions.php  jobs.php  events.php  (commands.php)
│   │       └── migrations/           M<yymmddhhmmss>Blog*.php
│   │
│   ├── Platform/                     reusable platform services (Identity, Access, Queue, Kafka, Audit, Settings, Gii …)
│   ├── Shared/                       framework glue: exceptions, Uuid, DB, Redis, HTTP (Responder), views ($h)
│   └── Console/Command/              platform console commands
│
├── config/
│   ├── routes/                       area entry points only: middleware stack + discovery, no feature routes
│   │   ├── admin.php                 /admin   → src/Admin/*/routes.php + src/Modules/*/Admin/routes.php
│   │   ├── web.php                   /        → src/Web/{Home,Account,Notifications}/routes.php + src/Modules/*/Web/routes.php
│   │   ├── api-v1.php                /api/v1  → src/Api/V1/*/routes.php + src/Modules/*/Api/routes.php
│   │   ├── auth.php  system.php      → src/Web/Auth/routes.php, src/Web/System/routes.php
│   │   └── webhooks.php              /webhooks/{code}
│   ├── common/                       params, settings, features, aliases, platform DI, platform permissions/jobs
│   ├── console/  web/  environments/
│   └── configuration.php             yiisoft/config groups
│
├── resources/
│   ├── views/layouts/  components/  errors/    shared UI only (no feature views here)
│   └── messages/{uz,ru}/app.php      translations (source strings are English)
├── migrations/                       platform migrations (modules keep theirs in src/Modules/<Name>/migrations)
├── assets/  public/  themes/         CSS/JS sources, web root, design theme packs
├── tests/{Unit,Integration,Api,E2E,Security,Performance,Support}
└── docs/  infra/  observability/  scripts/
```

## Where does it go?

| I want to add … | Put it in |
|---|---|
| an admin page to an existing section | action in `src/Admin/<Section>/<X>Controller.php`, route in that folder's `routes.php`, template in its `views/` |
| a new admin section | new folder `src/Admin/<Section>/` (controller + `routes.php` + `views/`), sidebar entry in `src/Admin/Menu/AdminMenu.php`, permission in `config/common/permissions/platform.php` |
| a new business module | `./yii gii:module <Name> --fields=… --apply` (or Admin → Developer tools → Enterprise Gii) — it creates the whole `src/Modules/<Name>/` folder; then *Next steps* (migrate, rbac:sync, Kafka topics) from the same page |
| a page of the end-user site | `src/Web/<Area>/` (controller + `routes.php` + `views/`) |
| an API endpoint | `src/Api/V1/<Resource>/` or `src/Modules/<Name>/Api/` (controller with OpenAPI attributes + `routes.php`) |
| a reusable service (used by several sections/modules) | `src/Platform/<Area>/` |
| a shared view piece (pager, field, flash) | `resources/views/components/` |
| a migration | platform: `migrations/`; module: `src/Modules/<Name>/migrations/` (both are found by `./yii migrate:up`) |
| a permission | platform: `config/common/permissions/platform.php`; module: `src/Modules/<Name>/config/permissions.php` → `./yii rbac:sync` |
| a job / Kafka consumer | platform: `config/common/jobs/*.php`, `config/common/events.php`; module: `src/Modules/<Name>/config/jobs.php`, `events.php` |

Nothing has to be registered by hand: route files, module config, migrations and OpenAPI attributes are discovered
from these folders (`config/routes/*.php`, `config/common/di/modules.php`, `config/common/{jobs,events}.php`,
`RbacCatalog`, `params.php` migration paths, `config/common/di/api.php`).

## Views

Controllers render the template next to them with a path alias:

```php
return $this->responder->render('@admin/Kafka/views/topics', [...]);      // src/Admin/Kafka/views/topics.php
return $this->responder->render('@modules/Blog/Admin/views/index', [...]); // src/Modules/Blog/Admin/views/index.php
return $this->responder->render('@web/Account/views/security', [...], layout: 'web');
```

| Alias | Directory |
|---|---|
| `@admin` | `src/Admin` |
| `@web` | `src/Web` |
| `@api` | `src/Api` |
| `@modules` | `src/Modules` |
| `@views` | `resources/views` (layouts, components, errors) |

Inside a template: `$this->render('./_tabs', …)` (same folder), `$this->render('//components/pager', …)` (shared
component), `$this->render($h->view('@admin/Users/views/_field'), …)` (a partial of another folder — rare).
Layouts stay in `resources/views/layouts/{admin,web,auth}.php`.

## Namespaces

PSR-4, `App\` = `src/`; the namespace always equals the folder:

| Folder | Namespace |
|---|---|
| `src/Admin/Kafka/` | `App\Admin\Kafka` |
| `src/Web/Account/` | `App\Web\Account` |
| `src/Api/V1/Users/` | `App\Api\V1\Users` |
| `src/Modules/Blog/Admin/` | `App\Modules\Blog\Admin` |
| `src/Modules/Blog/Domain/` | `App\Modules\Blog\Domain` |

Templates (`views/*.php`), `routes.php`, `module.php`, `config/*.php` and migrations are not classes.

## Coming from Yii2

The ideas are familiar, the mechanics are Yii3 (PSR-7/15, DI container, yiisoft/config, no global `Yii::$app`):

| Yii2 | Here |
|---|---|
| `backend/` (advanced template) | `src/Admin/` — every section is a folder |
| `frontend/` | `src/Web/` |
| `modules/blog/Module.php` | `src/Modules/Blog/module.php` (manifest) + `config/` |
| `controllers/PostController::actionIndex()` | `Admin/BlogController::index()` — plain method, route declared in `routes.php` |
| `views/post/index.php` | `Admin/views/index.php` next to the controller |
| `models/Post.php` (ActiveRecord) | `Domain/Blog.php` (entity + rules) + `Infrastructure/BlogRepository.php` (yiisoft/db queries) |
| business logic in models/controllers | `Application/BlogService.php` (permissions, tenant check, transaction, audit, events) |
| `urlManager` rules | `routes.php` (`Route::get('/{id}')->action([...])->name('admin.blog.view')`) |
| `behaviors()` access control | route middleware `RequirePermission::for('blog.view')` + the check inside the service |
| `Yii::$app->user->can()` | `AccessService::can()` / `$can('perm')` in views |
| `console/controllers` | `src/Console/Command/` or `src/Modules/<Name>/config/commands.php` |
| `migrations/` | `migrations/` (platform) or `src/Modules/<Name>/migrations/` |
| Gii module/CRUD generator | Enterprise Gii — generates the complete module folder, tests included |

— Author: ShokirjonMK
