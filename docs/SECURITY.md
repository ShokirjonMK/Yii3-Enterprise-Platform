# Security

Security controls are enforced in middleware, in application services and in the database — never only in the UI.
The ASVS mapping with evidence is in [SECURITY_ASVS.md](SECURITY_ASVS.md).

## Authentication

| Control | Implementation |
|---|---|
| Password hashing | Argon2id (m=64 MiB, t=4, p=1) via `yiisoft/security` `PasswordHasher`; transparent rehash on login |
| Password policy | ≥ 12 chars, mixed case, digit, symbol, not common, not containing username, history of 5 (`PasswordPolicy`) |
| Unknown-user timing | password verification against a dummy Argon2id hash |
| Brute force | per-IP Redis limiter + per-IP/account counts in `login_attempts` (PostgreSQL) + account lockout (5 failures / 15 min) |
| MFA | TOTP (RFC 6238, ±1 step), replay protection (`mfa_last_used_step` conditional update), 10 single-use recovery codes (Argon2id hashed), secret encrypted at rest; mandatory for superadmin/platform_admin/security_admin/devops_sre |
| First login | bootstrap superadmin gets a random temporary password (shown once) + forced password change + forced MFA enrolment; bootstrap runs only while no superadmin exists |
| Sessions | Redis-backed, `use_strict_mode`, HttpOnly, SameSite=Lax, `__Host-` + Secure in production, id regenerated at every privilege change; server-side registry (`user_sessions`, SHA-256 ids) with idle (30 min) and absolute (12 h) timeouts, listing and remote revocation |
| Step-up | `RequireRecentAuth`: password + MFA within 5 minutes for critical POSTs |
| SSO | OIDC: state, nonce, PKCE S256, JWKS signature (RS/ES/PS only), iss/aud/azp/exp/nbf checks, HTTPS discovery, redirect allowlist; linking by (issuer, subject); e-mail linking only with `email_verified` and explicit opt-in; SSO-only accounts can never use passwords; `local_only` (break-glass) accounts can never use SSO |
| API tokens | opaque 256-bit, stored as SHA-256, prefixed (`yep_a_`, `yep_r_`, `yep_p_`) for secret scanning, scopes, expiry, refresh rotation with reuse detection (family revocation) |

## Authorization

- `yiisoft/rbac` with `rbac-db` storage; catalog in `config/common/permissions/*.php`; **default deny** (unknown permission = false).
- Route middleware (`RequirePermission`) **and** application service checks (`AccessService::assert`).
- Guard rails: only superadmin grants/revokes administrative roles; no self role changes; last active superadmin cannot be removed/deactivated.
- Tenant isolation: services restrict queries to the actor's tenant unless `tenants.cross`; foreign-tenant ids return 404 (IDOR).
- Optional admin IP allowlist (`security.allowed_admin_networks`).

## Web protections

- CSRF: `yiisoft/csrf` on every session-based group (HTMX sends `X-CSRF-Token`).
- XSS: output encoding by default (`$h->e()`, `$h->t()`), strict CSP (`default-src 'self'; script-src 'self'; style-src 'self'; frame-ancestors 'none'; object-src 'none'`), no inline scripts/styles, HTMX configured with `allowEval=false`, `allowScriptTags=false`, `selfRequestsOnly=true`.
- Headers: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, HSTS in production, `Cache-Control: no-store` for dynamic pages, `X-Powered-By` removed, `expose_php=Off`, nginx `server_tokens off`.
- Open redirect: post-login `return` accepts only same-origin relative paths.
- Clickjacking: `frame-ancestors 'none'`.
- SQL injection: parameterised queries only (yiisoft/db query builder / bound params); sort fields whitelisted.
- SSRF: outbound integrations validated by `UrlGuard` (scheme, DNS resolution, private/link-local/metadata ranges blocked).
- File uploads: size limit, extension allowlist, MIME sniffing, randomised storage keys, private bucket, download with `Content-Disposition: attachment`.
- Client IP: `X-Forwarded-For` honoured only from `TRUSTED_PROXIES`.

## Data protection

- Secrets at rest (MFA seeds, SSO/integration secrets, secret settings): XChaCha20-Poly1305 with keys derived from `APP_SECRET` per purpose (`Encrypter`).
- Logs, audit and integration samples pass through `Redactor` (passwords, tokens, Authorization, cookies, PINFL (14 digits), card numbers, JWTs, DSN credentials).
- Audit log: append-only (DB trigger) and HMAC-signed per row (`./yii audit:verify` detects tampering even if the trigger is bypassed).
- PINFL from OneID is stored only as the identity link subject; claims kept for audit are masked.

## Production hardening checklist

- `APP_ENV=prod` (debug is forced off even if `APP_DEBUG=true`), `APP_URL` https, `APP_SECRET` ≥ 32 random bytes from a secret manager.
- Dev tools: Gii vendor API disabled (`yiisoft/yii-gii.enabled=false`), Developer Tools section returns 404 in prod, dev dependencies not installed in the prod image.
- Secrets only from Kubernetes Secrets / external secret manager / GitLab protected variables — never in the repository (`.env` is git-ignored).
- Stack traces are never shown in public responses when debug is off (yiisoft/error-handler) and API errors return a generic message with a correlation id.

## Reporting vulnerabilities

Report privately to the platform security officer; do not open public issues. Include the correlation id (`X-Request-Id`) of affected requests.
