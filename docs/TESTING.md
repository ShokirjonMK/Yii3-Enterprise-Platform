# Testing

PHPUnit 11, five suites (`phpunit.xml.dist`):

| Suite | What | Runs against |
|---|---|---|
| `unit` | pure PHP (security primitives, OIDC validation with a mock IdP, state machines, clients with Guzzle MockHandler) | nothing |
| `integration` | services through the real DI container: PostgreSQL, Redis, Kafka, SMTP (Mailpit), S3 | `APP_ENV=test` → database `platform_test` (auto-migrated, RBAC-synced, seeded by `tests/bootstrap.php`) |
| `api` | REST API over HTTP (auth grants, scopes, errors, pagination, CORS, OpenAPI) | running stack (`E2E_BASE_URL`, default `http://nginx:8080`) |
| `e2e` | browser-like HTML flows: cookies, CSRF, HTMX headers, MFA, every admin section | running stack |
| `security` | upload validation, SSRF, webhook signatures over HTTP | running stack |

Plus `tests/Performance/k6/smoke.js` (k6 load test).

## Commands

```bash
# Git Bash on Windows: export MSYS_NO_PATHCONV=1
docker compose exec -T -e APP_ENV=test app php vendor/bin/phpunit --testsuite unit,integration
docker compose exec -T app php vendor/bin/phpunit --testsuite api,security
docker compose exec -T app php vendor/bin/phpunit --testsuite e2e
docker run --rm --network yep_frontend -v "$PWD/tests/Performance/k6:/scripts" grafana/k6:0.54.0 run /scripts/smoke.js
```

## Conventions

- HTTP tests create their own users with `Fixtures::user([...roles], mfa: true)`; users get `locale=en` (assertions use
  English source strings), are archived in `Fixtures::cleanup()`.
- `Fixtures::resetLoginThrottle()` in `setUp()` — all tests share one client IP and would otherwise hit the (real) IP throttle.
- `AdminSession::login([...roles])` logs in with password + TOTP; `AdminSession::reauth()` performs step-up re-authentication.
- External providers without credentials (OneID, GitLab, Kubernetes, Telegram, SMS) are tested with mocked HTTP and
  reported as UNVERIFIED in [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md).
- Latest results with exact numbers are recorded in IMPLEMENTATION_STATUS.md.

— Author: ShokirjonMK
