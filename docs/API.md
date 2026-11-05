# REST API v1

Base path: `/api/v1` · OpenAPI 3.1: `GET /api/v1/openapi.json` (generated from attributes, `./yii api:openapi`
writes `public/api/openapi.json`) · Swagger UI: `/api/docs`.

The API is **stateless**: no session, no cookies, no CSRF. Every request authenticates with a Bearer token.
It is gated by the feature flag `api.v1` (all routes answer `404` when the flag is off); the Projects
resource additionally requires `module.projects` for the caller's tenant.

## 1. Request pipeline

```
JSON errors → flag api.v1 → CORS → rate limit → JSON errors (inner) → 415 guard → JSON body parser
           → [Bearer authentication → RequireFeature → RequireApiPermission(permission, scope)] → action
```

Routes live in `src/Api/V1/*/routes.php + src/Modules/*/Api/routes.php` (loaded in name order into the `/api/v1` group by
`config/routes/api-v1.php`). A trailing catch-all route keeps unmatched requests inside the pipeline so that
they still get CORS/rate-limit headers and JSON `404` / `405 + Allow`.

| Piece | Class |
|---|---|
| Envelope + errors | `App\Api\V1\Http\ApiResponder`, `App\Shared\Infrastructure\Http\DomainErrorMiddleware::json()` |
| CORS | `App\Api\V1\Middleware\CorsMiddleware` (setting `api.cors_origins`) |
| Rate limit | `App\Api\V1\Middleware\RateLimitMiddleware` (`RedisRateLimiter`, setting `api.rate_limit_per_minute`) |
| 415 | `App\Api\V1\Middleware\ContentTypeGuard` |
| Body parsing | `yiisoft/request-body-parser` with `App\Api\V1\Http\JsonBadRequestHandler` (400 `invalid_json`) |
| Authentication | `App\Api\V1\Middleware\BearerAuthentication` = yiisoft/auth `Authentication` + `HttpBearer` + platform `IdentityWithTokenRepositoryInterface` |
| Authorization | `App\Api\V1\Middleware\RequireApiPermission::for('projects.view', 'projects.read')` — scope **and** RBAC permission |
| Feature gate | `App\Api\V1\Middleware\RequireFeature::for('module.projects')` |

## 2. Authentication

Tokens are opaque (256-bit random, stored as SHA-256): access `yep_a_…` (TTL 1 h), refresh `yep_r_…`
(TTL 30 d), personal access tokens `yep_p_…` (created in the web profile, step-up required).

### Password grant

```bash
curl -s http://localhost:8090/api/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"grant_type":"password","username":"j.doe","password":"…","mfa_code":"123456","scope":"projects.read projects.write"}'
```

```json
{"data":{"access_token":"yep_a_…","refresh_token":"yep_r_…","token_type":"Bearer","expires_in":3600,"scope":"projects.read projects.write"}}
```

* Login throttling, account lockout (5 failures → 15 min) and constant-time behaviour for unknown users are the
  same as for the web login (`AuthenticationService`). Locked/throttled → `429` + `Retry-After`.
* Accounts with MFA must send `mfa_code` (TOTP or recovery code): missing → `401 mfa_required`, wrong/replayed →
  `401 invalid_mfa_code`. Accounts whose role mandates MFA but have not enrolled → `403 mfa_enrollment_required`;
  forced password change pending → `403 password_change_required`.
* Wrong credentials → `401 invalid_grant` with the generic message "Invalid username or password.".
* `application/x-www-form-urlencoded` is accepted on the auth endpoints for OAuth client compatibility.

### Client credentials grant (service accounts)

```bash
curl -s http://localhost:8090/api/v1/auth/token -u 'client_id:client_secret' \
  -H 'Content-Type: application/json' -d '{"grant_type":"client_credentials","scope":"projects.read"}'
```

Uses `App\Platform\Api\ApiClientService::authenticate()` (table `api_clients`: secret hash, IP allowlist,
expiry, enabled, tenant status). The token is issued for the client's `service_user_id`; requested scopes must
be a subset of the client's scopes. No refresh token is returned (RFC 6749 §4.4.3). When the service class is not
deployed the grant answers `400 unsupported_grant_type`.

### Scopes

`profile`, `users.read`, `users.write`, `projects.read`, `projects.write`, or `*` (default when `scope` is omitted:
everything the user's RBAC permissions allow). A route requires **both** the scope and the RBAC permission:
missing scope → `403 insufficient_scope`, missing permission → `403 forbidden`.

### Refresh (rotation + reuse detection) and revocation

```bash
curl -s http://localhost:8090/api/v1/auth/refresh -H 'Content-Type: application/json' -d '{"refresh_token":"yep_r_…"}'
curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/api/v1/auth/revoke -H 'Content-Type: application/json' -d '{"token":"yep_a_…"}'   # 204
```

Every refresh returns a new pair and revokes the previous family members. Presenting an already-rotated
refresh token is treated as token theft: the **whole family** (including the newest access token) is revoked and
`401 invalid_grant` is returned. `revoke` revokes the family of the presented access or refresh token and always
answers `204` (RFC 7009).

### Current principal

```bash
curl -s http://localhost:8090/api/v1/me -H "Authorization: Bearer $TOKEN"
```

Returns `user`, `roles`, `permissions`, `scopes`, `auth_method`.

## 3. Envelopes

Success:

```json
{"data": {…} | […], "meta": {"pagination": {"page":1,"per_page":25,"total":42,"pages":2,"sort":"-created_at"}},
 "links": {"self":"…","first":"…","last":"…","prev":null,"next":"/api/v1/projects?page=2&per_page=25"}}
```

`meta`/`links` are present on collections (and `meta` on a few actions, e.g. `temporary_password` after
creating a user or `approval` on a pending archive). Single resources carry `ETag: "<version>"`;
created resources return `201` + `Location`.

Error (all non-2xx, rendered by `DomainErrorMiddleware` in JSON mode or the API middleware):

```json
{"error":{"code":"validation_failed","message":"Validation failed.","details":{"name":["Name must be between 2 and 200 characters."]},"correlation_id":"5f0c…"}}
```

`correlation_id` equals the `X-Request-Id` response header and the `correlation_id` of server logs/audit.

| Status | `error.code` | When |
|---|---|---|
| 400 | `invalid_json`, `invalid_body`, `unsupported_grant_type`, `invalid_scope`, `invalid_client` | malformed request |
| 401 | `unauthorized`, `invalid_grant`, `mfa_required`, `invalid_mfa_code`, `invalid_client` | missing/invalid credentials (`WWW-Authenticate: Bearer realm="api"`) |
| 403 | `forbidden`, `insufficient_scope`, `mfa_enrollment_required`, `password_change_required` | authorization |
| 404 | `not_found` | unknown route/resource, **objects of another tenant** (IDOR protection), feature flag off |
| 405 | `method_not_allowed` | path exists, method does not (`Allow` header) |
| 409 | `stale_version`, `invalid_transition`, `project_archived`, `self_deactivation`, `approval_pending`, … | conflicts |
| 415 | `unsupported_media_type` | body is not `application/json` |
| 422 | `validation_failed` | field errors in `details` (field → list of messages) |
| 428 | `precondition_required` | `PATCH` without `If-Match` / `version` |
| 429 | `too_many_requests` | rate limit, login throttling or lockout (`Retry-After`) |
| 500 | `internal_error` | unexpected error; message contains only the correlation id |
| 503 | `unavailable` | `/health` when the database is unreachable |

## 4. Collections: pagination, sorting, filtering

* `page` (≥ 1, default 1), `per_page` (1–100, default = setting `api.default_page_size`); out-of-range → `422`.
* `sort=<field>` ascending, `sort=-<field>` descending; only whitelisted fields (see OpenAPI per resource), other
  values → `422`.
* `filter[<key>]=<value>` for whitelisted keys, comma-separated lists where documented
  (`filter[status]=active,on_hold`), free-text `q`.
* Unknown query parameters are ignored. `X-Total-Count` mirrors `meta.pagination.total`.

```bash
curl -s "http://localhost:8090/api/v1/projects?filter[status]=active,on_hold&filter[tag]=crm&sort=-due_on&per_page=50&q=portal" \
  -H "Authorization: Bearer $TOKEN"
```

## 5. Optimistic locking

Every mutable resource has an integer `version` (also the `ETag`). `PATCH` requires `If-Match: "<version>"` (or
`"version"` in the body); a mismatch yields `409 stale_version`, a missing precondition `428`. Status changes and
archive accept an optional version.

```bash
curl -s -X PATCH http://localhost:8090/api/v1/projects/$ID -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'If-Match: "3"' -d '{"name":"Portal v2","tags":["crm","portal"]}'
```

## 6. Resources

| Method | Path | Permission / scope |
|---|---|---|
| POST | `/auth/token`, `/auth/refresh`, `/auth/revoke` | public |
| GET | `/me` | any valid token |
| GET | `/health`, `/openapi.json` | public |
| GET | `/users` · `/users/{id}` | `users.view` / `users.read` |
| POST · PATCH | `/users` · `/users/{id}` | `users.manage` / `users.write` |
| POST | `/users/{id}/block` · `/users/{id}/unblock` | `users.manage` / `users.write` |
| GET | `/projects` · `/projects/{id}` | `projects.view` / `projects.read` |
| POST | `/projects` | `projects.create` / `projects.write` |
| PATCH | `/projects/{id}` | `projects.update` / `projects.write` (+ owner or `projects.manage_all`) |
| DELETE | `/projects/{id}` (archive) | `projects.delete` / `projects.write` |
| POST | `/projects/{id}/status` | `projects.update` / `projects.write` (draft→active also `projects.approve`) |
| POST | `/projects/reports` → `202` | `projects.view` / `projects.read` |
| GET | `/projects/reports/{id}` | `projects.view` / `projects.read` |

Archiving a **completed** project goes through the workflow `project.lifecycle` (when enabled): the response is
`202` with `meta.approval = {id, status: "pending"}`; after an approver with `approvals.decide` (four-eyes)
accepts the request the project becomes `archived`.

```bash
# lifecycle
curl -s -X POST http://localhost:8090/api/v1/projects/$ID/status -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"status":"active","comment":"Kick-off"}'
# background report
curl -s -X POST http://localhost:8090/api/v1/projects/reports -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"status":"active"}'
```

## 7. Rate limits

Fixed 60-second window in Redis, shared by all instances:

* requests with a Bearer token are counted per token, anonymous requests per client IP
  (`X-Forwarded-For` honoured only from trusted proxies);
* an extra per-IP ceiling of 10× the limit prevents bypass by rotating tokens;
* limit = setting `api.rate_limit_per_minute` (default 600; Admin → Settings → API).

Every response carries `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (seconds until the window
resets). Over the limit: `429 too_many_requests` + `Retry-After`. If Redis is unavailable the limiter fails open
(logged); the token endpoint keeps its PostgreSQL-backed login throttling. nginx adds a coarse per-IP limit
(`limit_req zone=api`, 50 r/s burst 100) in front.

## 8. CORS

Allowed origins come from the setting `api.cors_origins` (JSON list, exact match, e.g.
`["https://portal.example.uz"]`). Preflight (`OPTIONS` + `Access-Control-Request-Method`) is answered with `204`,
`Access-Control-Allow-Methods`, `Access-Control-Allow-Headers` (`Authorization, Content-Type, Accept,
Accept-Language, If-Match, X-Request-Id`) and `Access-Control-Max-Age: 600`. Actual responses (including errors)
carry `Access-Control-Allow-Origin` and `Access-Control-Expose-Headers` (ETag, Location, Retry-After, X-Request-Id,
X-Total-Count, X-RateLimit-*). Credentials mode is never enabled — the API does not use cookies. Unknown origins get
no CORS headers.

## 9. Versioning policy

* The major version is part of the path (`/api/v1`). Within v1 only **backwards-compatible** changes are made:
  new endpoints, new optional request fields, new response fields, new enum values in documented-open fields,
  new error codes for new situations. Clients must ignore unknown response fields.
* Breaking changes (removing/renaming fields, changing types or semantics, tightening validation of existing
  fields) ship as `/api/v2`; v1 and v2 then run in parallel for at least 6 months. Deprecated operations are
  marked `deprecated: true` in OpenAPI and answer with `Deprecation` / `Sunset` headers before removal.
* Event payloads on Kafka follow their own `schema_version` rules (docs/ARCHITECTURE.md).

## 10. OpenAPI and Swagger UI

* Operations are documented with `zircote/swagger-php` attributes on the action classes in `src/Api/V1/<Resource>`;
  shared schemas/responses/parameters live in `src/Api/V1/OpenApi/OpenApiSpec.php`.
* `./yii api:openapi` writes `public/api/openapi.json` (run in CI/image build); `GET /api/v1/openapi.json`
  generates on the fly outside production and serves the file in production.
* `/api/docs` serves Swagger UI 5.17.14 from local assets (`public/static/swagger-ui/`, Apache-2.0 `LICENSE`
  included) with a page-specific CSP allowing only `'self'` (initializer `public/static/api-docs.js`, no inline
  script). The `api.v1` flag also gates the docs page.

## 11. Testing

```bash
docker compose exec -T app php vendor/bin/phpunit --testsuite api   # tests/Api/*Test.php over http://nginx:8080
```

— Author: ShokirjonMK
