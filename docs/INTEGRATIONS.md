# Integrations (Admin section 07) — outbound HTTP, SSRF guard, webhooks, alerts

## 1. Platform HTTP client

`App\Platform\Integrations\Http\HttpClient` is the **only** way platform code talks to external HTTP systems
(integrations, Telegram, SMS gateway, alerting). It is built on Guzzle 7 `HandlerStack` middleware:

| Order (outer → inner) | What it does |
|---|---|
| retry | exponential backoff `backoff_ms · 2^(n-1)` + jitter `[0, backoff_ms]`, max `max_retries`; only idempotent methods (GET/HEAD/OPTIONS/PUT/DELETE) unless the call passes `['retry' => true]`; retried on 408/429/500/502/503/504 and network/timeout errors (not TLS); `Retry-After` honoured up to 30 s (longer → no retry, caller/job decides) |
| rate limit | `RedisRateLimiter` key `integration:<code>`, `rate_limit_per_minute` (0 = unlimited) → `RateLimitedException` (`rate_limited_local`) |
| circuit breaker | `CircuitBreaker` (Redis): closed → open after `circuit_threshold` failures (5xx/408/429/transport) → half-open after `circuit_cooldown_s` with **one** probe (SET NX) → closed on success / open again on failure. Redis down = fail-open + warning |
| headers/auth | `X-Request-Id` (RequestContext correlation id), W3C `traceparent`, auth `none / bearer / basic / api_key / hmac` |
| log + metrics | one `integration_logs` row per attempt: redacted headers (auth/key/token/secret/signature/cookie names → `***`), URL and body samples redacted with `Redactor` (JSON structurally) + caller-provided secrets masked, ≤ 2 KB; metrics `platform_integration_requests_total{integration,method,status,outcome}` and `platform_integration_request_duration_seconds{integration}` |

Before anything is sent the destination passes the **SSRF guard** and the connection is pinned to the vetted IP
(`CURLOPT_RESOLVE`, closes DNS rebinding). Redirects are **never** followed (`allow_redirects=false`).

```php
// configured integration (row in `integrations`)
$response = $integrations->call('crm', 'GET', '/v1/customers', ['query' => ['page' => 1]]);
// ad-hoc provider profile
$data = $http->sendJson(IntegrationConfig::adhoc('acme', 'https://api.acme.test'), 'POST', '/orders', ['json' => $order, 'retry' => true]);
```

### Error classes (`App\Platform\Integrations\Exception\*`, all extend `IntegrationException`)

| HTTP / cause | Exception | `errorClass()` | retryable |
|---|---|---|---|
| 401 | `UnauthorizedException` | unauthorized | no |
| 403 | `ForbiddenException` | forbidden | no |
| 404 | `NotFoundException` | not_found | no |
| other 4xx | `ClientErrorException` | client_error | no |
| 408, local timeout | `RequestTimeoutException` | timeout | yes |
| 429 (`retryAfter()`) | `RateLimitedException` | rate_limited / rate_limited_local | yes |
| 500 / other 5xx | `ServerErrorException` | server_error | yes |
| 502 / 503 / 504 | `BadGatewayException` / `ServiceUnavailableException` / `GatewayTimeoutException` | bad_gateway / unavailable / gateway_timeout | yes |
| DNS / connect / TLS / other | `NetworkException` | dns / connect / tls / network | yes (TLS: no) |
| breaker open (`retryAfter()`) | `CircuitOpenException` | circuit_open | yes |
| SSRF guard | `SsrfBlockedException` | ssrf_blocked / dns | no |
| disabled / missing URL or secret | `NotConfiguredException` | not_configured | no |

Job handlers: `isRetryable() === false` → throw `PermanentJobFailure`, otherwise rethrow (queue backoff).

## 2. SSRF guard — `App\Platform\Security\UrlGuard`

* only `http`/`https`, host required, no credentials in the URL;
* host resolved (A + AAAA); **every** address must be public. Blocked: 0/8, 10/8, 100.64/10, 127/8, 169.254/16,
  172.16/12, 192.0.0/24, 192.0.2/24, 192.88.99/24, 192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4, 240/4,
  ::, ::1, IPv4-mapped (checked as IPv4), 64:ff9b::/96, 100::/64, 2001:db8::/32, fc00::/7, fe80::/10, fec0::/10, ff00::/8;
* `integrations.allow_private_network = true` permits private ranges (internal services) — changing it requires
  step-up re-authentication and is audited as a security event;
* cloud metadata (169.254.169.254, 169.254.170.2, fd00:ec2::254, 100.100.100.200) is blocked **always**.

Also used for SMS gateway health and Web Push endpoints (user-controlled URLs, checked on subscribe and on send).

## 3. Integrations administration (`/admin/integrations`)

`integrations.view`: list (search, status filter, sort), detail, circuit state, recent calls (`/admin/integrations/logs`,
per-attempt detail with redacted samples). `integrations.manage`: create/edit (optimistic `version`), enable/disable
(requires base URL and — for authenticated types — a secret), **test connection** (GET `health_path`, one attempt, sets
`status`, `last_health_at`, `last_error`), reset circuit. **Step-up (RequireRecentAuth)**: credential (write-only,
`Encrypter` purpose `integration`), private-network access, delete. Every change is audited (`integration.*`), secrets never.

Status tracking: `unconfigured` (never tested) → `healthy` after a successful call/test → `degraded` after a
server/transport/401 failure; `failed` after a failed test; `maintenance` is manual.

## 4. Inbound webhooks

Route: **`POST /webhooks/{code}`** — `config/routes/webhooks.php` (no session/CSRF, JSON errors).
> The lead must add `'routes/webhooks.php'` to `config/configuration.php` → `config-plugin.routes` and run
> `composer yii-config-rebuild`. Until then the pipeline is tested at service level (`tests/Integration/WebhookReceiveTest`).

Sender contract:

```
X-Timestamp: <unix seconds>
X-Signature: sha256=<hex HMAC-SHA256(secret, X-Timestamp + "." + raw body)>
X-Webhook-Id: <event id>          (optional; else body "id"/"event_id"; else sha256(body))
```

Pipeline (`WebhookService::receive`): enabled endpoint (else 404) → body ≤ 1 MB (413) → rate limit 1200/min per
endpoint (429) → timestamp within `tolerance_seconds` and constant-time signature compare (`hash_equals`, several
candidates allowed for sender-side rotation) → **401 + security audit `webhook.signature_invalid`** (invalid deliveries are
*not* stored, so attackers cannot pre-claim event ids) → `INSERT … ON CONFLICT (endpoint_id, external_id) DO NOTHING`
→ duplicate = `200 {"status":"duplicate"}`; new = job `webhook_endpoints.job_type` (default `platform.webhooks.ack`,
which sets `processed_at`) enqueued in the same transaction → **`202 {"status":"accepted"}`**.

Admin (`/admin/integrations/webhooks`): CRUD, enable/disable, **secret shown exactly once** on creation/rotation
(rotation and delete require step-up), received events with redacted payloads.

Outbound signing: `WebhookSigner::headers($secret, $body)` or an integration with `auth_type = hmac`
(header names configurable).

## 5. Alerts — `AlertNotifier`

`$alerts->alert($title, $message, 'critical', ['integration' => 'crm'])` → Telegram `sendMessage` to
`notifications.telegram_alert_chat` (token `notifications.telegram_bot_token`), **fallback** e-mail to `app.support_email`.
Identical alerts are suppressed for 5 minutes; context is redacted; the method never throws.

## 6. Status

| Item | Status | Evidence |
|---|---|---|
| HttpClient (retry/backoff/jitter, breaker, rate limit, tracing, redacted logs, metrics, typed errors) | TESTED | `tests/Integration/IntegrationsHttpClientTest` (MockHandler + real Redis/PG), `tests/Unit/IntegrationPrimitivesTest` |
| SSRF guard | TESTED | `tests/Security/SsrfTest` (real DNS inside the stack: nginx/redis/localhost/metadata blocked) |
| IntegrationService / admin UI | TESTED | `tests/Integration/IntegrationsServiceTest`, `tests/E2E/Admin/IntegrationsAdminTest` |
| Webhooks (service level) | TESTED | `tests/Integration/WebhookReceiveTest` |
| `POST /webhooks/{code}` over HTTP | UNVERIFIED | route file must be registered in `config/configuration.php` |
| AlertNotifier | TESTED (mock Telegram, real Mailpit) | `tests/Integration/ChannelsProvidersTest` |
| Real external providers | UNVERIFIED | need real endpoints/credentials |

— Author: ShokirjonMK
