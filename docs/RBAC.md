# RBAC & access control

Authorization is built on `yiisoft/rbac` + `yiisoft/rbac-db` (tables `yii_rbac_item`, `yii_rbac_item_child`,
`yii_rbac_assignment`). Every decision is **default deny** and is checked twice: by route middleware
(`RequirePermission::for(...)`) and again inside the application service (`AccessService::assert()`).

## Catalog

The catalog is code, not data: `config/common/permissions/*.php` (one file per module, merged by `RbacCatalog`).

```php
return [
    'permissions' => ['projects.view' => 'View projects'],
    'roles' => ['business_admin' => ['description' => '…', 'permissions' => ['projects.view'], 'children' => ['user']]],
];
```

Apply changes with `./yii rbac:sync` or **Admin → RBAC → Sync catalog** (needs `rbac.manage` + step-up re-auth;
audited as `rbac.catalog.synced`). Sync creates missing permissions/roles, updates descriptions, adds/removes
parent→child links. It **never deletes assignments**. The RBAC page shows a warning when the storage is behind the
catalog (missing permissions or roles).

Role `superadmin` has `*` and so gets every permission declared in any catalog file.

## Built-in roles

| Role | Purpose | Inherits |
|---|---|---|
| `user` | Regular authenticated user (`profile.self`) | — |
| `auditor` | Read-only access to all admin sections and the audit log | user |
| `developer` | Developer tools, read-only operations | user |
| `business_admin` | Users of **own tenant**, workflows, approvals | user |
| `security_admin` * | SSO, sessions, security center, role assignment | auditor |
| `devops_sre` * | Infrastructure, data stores, jobs, deployments, backups | auditor |
| `platform_admin` * | All tenants (`tenants.cross`), users, auth, RBAC, API, settings | auditor, business_admin |
| `superadmin` * | Break-glass owner, every permission | platform_admin, devops_sre, security_admin, developer |

\* administrative roles (`RbacCatalog::ADMIN_ROLES`).

The permission matrix (**Admin → RBAC → Permission matrix**, route `admin.rbac.matrix`) is generated from the
catalog with inheritance resolved; it is read-only.

## Guard rails (enforced in `AccessService::setRoles`)

1. Administrative roles can be granted or revoked **only by a superadmin** (`AccessDeniedException` → HTTP 403).
2. A user cannot change their own roles.
3. The last **active** superadmin can never lose the role (`ConflictException last_superadmin`).
4. SSO auto-provisioning can never grant an administrative role.

Additional rules in the admin UI / `UserService`:

- `UserService::assertMayAdminister()` — accounts that hold an administrative role can be edited, blocked,
  archived, unlocked, password-reset, have sessions revoked or SSO identities unlinked **only by a superadmin**.
- Self-deactivation and deactivation of the last active superadmin are refused.
- Role changes (`admin.users.roles`, `admin.rbac.assign`, `admin.rbac.revoke`) require `rbac.manage` **and** a recent
  step-up re-authentication (password + TOTP, `RequireRecentAuth`, 5 minutes).
- Every change is audited (`rbac.roles.changed`, severity `warning` when an admin role is added).

## Tenant isolation

Actors without `tenants.cross` see only their own tenant. Applied in `UserService`, `OrganizationService`,
`TenantService`, `ApiClientService` and the RBAC "users per role" lists. Objects of another tenant are reported as
**404 Not Found** (no existence oracle / IDOR). Role assignment loads the target through `UserService::get()` first,
so foreign-tenant users cannot be targeted.

## Permissions used by sections 02–06

| Section | View | Manage | Extra |
|---|---|---|---|
| 02 Users & Organizations (`admin.users*`, `admin.organizations*`) | `users.view` | `users.manage` | `sessions.revoke` (terminate sessions), `rbac.manage` (roles); archive / reset password / unlink SSO need re-auth |
| 03 Authentication (`admin.auth*`) | `auth.view` | `auth.manage` | login-method switches also need `settings.manage` (feature flags); create/edit/enable/disable/delete provider need re-auth |
| 04 RBAC (`admin.rbac*`) | `rbac.view` | `rbac.manage` | re-auth for assign/revoke/sync |
| 05 Multi-Tenancy (`admin.tenants*`) | `tenants.view` | `tenants.manage` | create/delete/status change also need `tenants.cross`; flag overrides also need `settings.manage`; status/delete need re-auth |
| 06 API Management (`admin.api*`) | `api.view` | `api.manage` | rotate secret / delete need re-auth |

## Tests

- `tests/Integration/AccessSettingsAuditTest` — inheritance, admin-role guard, last superadmin.
- `tests/Integration/IdentityAdminServicesTest` — tenant isolation of organizations/tenants/API clients.
- `tests/E2E/Admin/*` — 403 for lower roles, 404 for foreign-tenant objects, CSRF rejection, admin-role grant by a
  non-superadmin refused, re-auth gates.

— Author: ShokirjonMK
