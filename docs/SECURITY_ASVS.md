# OWASP ASVS 4.0.3 — Level 2 checklist

This file is the single source of truth for the ASVS status of the Yii3 Enterprise Platform. It is rendered in the
admin panel (Security Center → OWASP ASVS, `src/Platform/Monitoring/AsvsChecklist.php`) and must be updated in the
same merge request as any security-relevant change.

Status values: **implemented** (control exists in code and is covered by an automated test or verifiable config),
**partial** (control exists but has a known gap — described in the evidence column), **n/a** (not applicable to this
system), **planned** (not implemented yet). Evidence points to real code, configuration or tests in this repository.

Scope: Level 2 requirements of chapters V1–V14. Requirement texts are abbreviated; see
https://github.com/OWASP/ASVS/tree/v4.0.3/4.0 for the normative wording.

## V1 Architecture, Design and Threat Modeling

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 1.1.1 | Secure software development lifecycle in use | partial | AGENTS.md ground rules, MR review; formal SSDLC sign-off is organisational |
| 1.1.2 | Threat modeling for design changes | planned | Threat model document not yet written |
| 1.2.1 | Unique low-privilege OS accounts for components | implemented | infra/docker/php/Dockerfile (non-root USER_ID/GROUP_ID), separate DB user |
| 1.2.2 | Authenticated communication between components | partial | DB/Redis passwords, Kafka SASL support (KAFKA_SASL_*); mTLS between pods not configured in dev |
| 1.2.3 | Single vetted authentication mechanism | implemented | src/Platform/Identity/Application/AuthenticationService.php (one path for local + SSO) |
| 1.2.4 | All authentication pathways equally strong | implemented | MFA gate applies to local and SSO logins: src/Web/Auth/WebAuthSession.php gates() |
| 1.4.1 | Trusted enforcement points for access control | implemented | src/Web/Middleware/RequirePermission.php + AccessService::assert in services |
| 1.4.4 | Single well-vetted access control mechanism | implemented | yiisoft/rbac + rbac-db via src/Platform/Access/AccessService.php |
| 1.4.5 | Attribute/feature-based access control | implemented | permissions per feature: config/common/permissions/platform.php |
| 1.5.1 | Input/output requirements defined | partial | ValidationException-based validation in services; no central schema registry |
| 1.5.3 | Input validation enforced on a trusted layer | implemented | server-side validation in application services (e.g. DeploymentService::request) |
| 1.6.1 | Explicit policy for cryptographic keys | implemented | src/Shared/Infrastructure/Security/Encrypter.php (key derivation from APP_SECRET per purpose) |
| 1.6.2 | Consumers of crypto services protect key material | implemented | secrets only via env / encrypted settings (type secret), never in DB plaintext |
| 1.7.1 | Common logging format | implemented | src/Shared/Infrastructure/Logging/JsonStreamTarget.php (JSON, correlation id) |
| 1.7.2 | Logs transmitted securely to a remote system | partial | stdout JSON for the cluster log collector; transport security depends on the cluster |
| 1.8.1 | Sensitive data identified and classified | partial | Redactor::SENSITIVE_KEYS; no formal data classification register |
| 1.9.1 | Encrypted communication between components | partial | DB_SSLMODE / Kafka SSL supported; disabled in the local dev stack |
| 1.11.1 | Business logic flows documented | partial | docs/OPERATIONS.md (deploy four-eyes, incidents); per-module docs pending |
| 1.14.6 | No unsupported/insecure client-side technologies | implemented | server-rendered HTML + HTMX, no Flash/Java applets |

## V2 Authentication

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 2.1.1 | Passwords at least 12 characters | implemented | src/Platform/Identity/Application/PasswordPolicy.php (minLength 12, params security.password) |
| 2.1.2 | Passwords of 64+ characters permitted | implemented | PasswordPolicy has no upper limit below 128 |
| 2.1.4 | Any printable Unicode allowed | implemented | PasswordPolicy validates by character classes only |
| 2.1.5 | Users can change their password | implemented | src/Web/Auth/AccountSecurityController.php passwordSubmit |
| 2.1.6 | Password change requires current password | implemented | UserService::changeOwnPassword |
| 2.1.7 | Passwords checked against breached lists | planned | HIBP k-anonymity check not integrated |
| 2.1.9 | No composition rules beyond length | partial | composition rules (mixed case, digit, symbol) are enabled by policy request of the customer |
| 2.1.10 | No periodic rotation requirement | partial | security.password_max_age_days (default 180) is a customer requirement; can be set to 0 |
| 2.1.12 | User can view the masked password temporarily | n/a | browser-native control |
| 2.2.1 | Anti-automation (lockout / throttling) | implemented | src/Platform/Identity/Application/LoginThrottle.php (per user + per IP), tests/E2E/AuthFlowTest |
| 2.2.2 | Weak authenticators (SMS/e-mail) limited | implemented | MFA is TOTP only (MfaService); SMS not used as an authenticator |
| 2.2.3 | Notifications after authentication changes | implemented | template security.new_login (PlatformSeeder), setting security.new_login_notification |
| 2.3.1 | Initial passwords random and short-lived | implemented | UserService::resetPassword + must_change_password gate; bootstrap token for superadmin |
| 2.4.1 | Passwords stored with approved one-way KDF | implemented | Argon2id via yiisoft/security PasswordHasher |
| 2.4.4 | bcrypt work factor / Argon2 parameters adequate | implemented | PHP default Argon2id parameters (memory 64 MiB) |
| 2.5.1 | No secrets sent in recovery | implemented | password reset by administrator; no password hint/e-mail of password |
| 2.5.2 | No password hints / knowledge-based answers | implemented | not present in the data model (migrations/M261104000001CoreIdentity.php) |
| 2.5.4 | No shared or default accounts | implemented | platform:install creates a one-time superadmin with forced password change |
| 2.5.6 | Secure recovery mechanism (MFA recovery codes) | implemented | MfaService recovery codes (hashed, single use) |
| 2.7.1 | Out-of-band authenticators not over PSTN | n/a | no OOB authenticators |
| 2.8.1 | Time-based OTPs have a defined lifetime | implemented | TOTP 30 s window (spomky-labs/otphp) |
| 2.8.3 | Approved algorithms for OTP | implemented | HMAC-SHA1 TOTP per RFC 6238 |
| 2.8.4 | TOTP used only once in validity period | implemented | users.mfa_last_used_step replay protection; AccessSettingsAuditTest::testMfaReplayProtection |
| 2.8.6 | Physical OTP can be revoked | n/a | software TOTP; disabling MFA by admin resets enrolment |
| 2.9.1 | Cryptographic keys for verification stored securely | n/a | no client certificates / hardware keys |
| 2.10.1 | Service credentials not hard-coded | implemented | all integration credentials in env or encrypted settings (config/common/settings.php type secret) |
| 2.10.2 | Service accounts not default credentials | implemented | API clients with hashed secrets (api_clients.secret_hash) |
| 2.10.4 | Secrets stored in a secret manager / not in source | implemented | .env only for local dev; production via Kubernetes Secrets (docs/BACKUP_RECOVERY.md, docs/OPERATIONS.md) |

## V3 Session Management

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 3.1.1 | Session token never in URL | implemented | cookie-based sessions (config/common/params.php session cookie params) |
| 3.2.1 | New session token on authentication | implemented | session id regenerated in WebAuthSession::complete |
| 3.2.2 | Session tokens with ≥ 64 bits entropy | implemented | PHP session ids (sid_length 48, bits 6) + registry hash (SessionRegistry::hash) |
| 3.2.3 | Tokens stored securely in the browser | implemented | HttpOnly, SameSite=Lax, Secure in prod (params cookie_httponly / cookie_secure) |
| 3.3.1 | Logout invalidates the session | implemented | WebAuthSession::logout → SessionRegistry::revokeCurrent; AuthFlowTest |
| 3.3.2 | Idle / absolute timeouts | implemented | SessionRegistry idleTimeout 1800 s, absoluteTimeout 43200 s; setting security.session_idle_minutes |
| 3.3.3 | Option to terminate all other sessions after password change | implemented | UserService::changeOwnPassword(keepSessionId) revokes others |
| 3.3.4 | Users can view and log out active sessions | implemented | web.security (own sessions); admin Security Center → Sessions (admin.security.sessions) |
| 3.4.1 | Cookie Secure attribute | implemented | cookie_secure in prod; HSTS in SecurityHeadersMiddleware |
| 3.4.2 | Cookie HttpOnly attribute | implemented | cookie_httponly = 1 |
| 3.4.3 | Cookie SameSite attribute | implemented | cookie_samesite = Lax + CSRF tokens |
| 3.4.4 | __Host- prefix | partial | cookie name without __Host- prefix; planned with the production domain |
| 3.4.5 | Path attribute as precise as possible | implemented | single application at / |
| 3.5.1 | OAuth refresh tokens revocable | implemented | ApiTokenService::revokeFamily / revokeAllForUser; Security Center "revoke all API tokens" |
| 3.5.2 | Session tokens, not static API secrets, for users | implemented | short-lived access tokens + rotating refresh tokens (ApiTokenService::refresh) |
| 3.5.3 | Stateless tokens protected by signature | n/a | API tokens are opaque and stored hashed (api_tokens.token_hash) |
| 3.7.1 | Re-authentication before sensitive operations | implemented | src/Web/Middleware/RequireRecentAuth.php (password + TOTP, 300 s) on backups, deploys, scaling |

## V4 Access Control

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 4.1.1 | Access control enforced server-side | implemented | RequirePermission on every admin route (src/Admin/*/routes.php) |
| 4.1.2 | Attributes used by access control cannot be manipulated | implemented | permissions resolved from yii_rbac_* tables, never from the request |
| 4.1.3 | Principle of least privilege | implemented | 8 roles with scoped permissions (config/common/permissions/platform.php) |
| 4.1.5 | Access controls fail securely | implemented | default deny: RequirePermission throws AccessDeniedException |
| 4.2.1 | Protection against IDOR | implemented | tenant restriction → NotFoundException (UserService::load, SecurityCenter::user, AuditSearch) |
| 4.2.2 | Anti-CSRF on state-changing requests | implemented | yiisoft/csrf CsrfTokenMiddleware in config/routes/admin.php |
| 4.3.1 | Administrative interfaces use MFA | implemented | MFA enforced for privileged roles (params security.mfa.enforceForRoles), posture check mfa_privileged |
| 4.3.2 | Directory browsing disabled | implemented | infra/docker/nginx/default.conf (front controller only) |
| 4.3.3 | Additional authorization for high-value operations | implemented | four-eyes deployment approval (DeploymentService::assertFourEyes, DB constraint deployments_four_eyes) |

## V5 Validation, Sanitization and Encoding

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 5.1.1 | HTTP parameter pollution defences | implemented | inputs cast explicitly per key ((string) $body['x']) in controllers |
| 5.1.2 | Mass assignment protection | implemented | services whitelist columns (e.g. IncidentService::open builds explicit rows) |
| 5.1.3 | Input validation (positive allow-lists) | implemented | e.g. KubernetesClient::DNS1123, deployment version regex, AuditSearch::normalize |
| 5.1.4 | Structured data strongly typed | implemented | typed settings (SettingsService::validate) |
| 5.1.5 | URL redirects to allow-listed destinations | implemented | RequireLogin::safeReturn (relative paths only) |
| 5.2.1 | Untrusted HTML sanitized | implemented | no user HTML accepted; all output encoded by ViewHelpers::e/t |
| 5.2.4 | No eval / dynamic code execution | implemented | HTMX allowEval=false (layouts/admin.php meta htmx-config) |
| 5.2.5 | Template injection protection | implemented | PHP views without user-controlled templates; notification templates use strtr placeholders |
| 5.2.6 | SSRF protection | partial | outbound URLs only from admin-managed settings (format url); no egress allow-list in dev |
| 5.3.1 | Output encoding relevant to the context | implemented | Html::encode in ViewHelpers; JSON_HEX_TAG for inline JSON |
| 5.3.3 | Context-aware escaping against XSS | implemented | strict CSP without unsafe-inline (params security.csp) |
| 5.3.4 | Parameterized queries | implemented | yiisoft/db query builder / bound params everywhere; Paginator sort whitelist |
| 5.3.6 | JSON injection protection | implemented | json_encode for all JSON output (Responder::json) |
| 5.3.8 | OS command injection protection | implemented | BackupService::exec uses proc_open with an argument array (no shell) |
| 5.3.10 | XPath/XML injection | n/a | no XML processing of user input |
| 5.5.2 | XML parsers restrict external entities | n/a | no XML parsing |

## V6 Stored Cryptography

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 6.1.1 | Regulated private data encrypted at rest | partial | secrets/MFA seeds encrypted (Encrypter); full-disk/volume encryption is an infrastructure control |
| 6.2.1 | Crypto modules fail securely | implemented | sodium AEAD (Encrypter) throws on authentication failure |
| 6.2.2 | Industry-proven crypto | implemented | libsodium XChaCha20-Poly1305, HMAC-SHA256, Argon2id |
| 6.2.3 | Approved IV/nonce/mode | implemented | random nonce per encryption (Encrypter) |
| 6.2.5 | No insecure algorithms | implemented | no MD5/SHA1 for security purposes (TOTP SHA1 per RFC excepted) |
| 6.3.1 | CSPRNG for random values | implemented | random_bytes, Uuid::v7 |
| 6.4.1 | Secrets management solution | partial | env + encrypted settings; Vault/KMS integration not implemented |
| 6.4.2 | Key material not exposed to the application | partial | APP_SECRET is held by the application process (derivation per purpose) |

## V7 Error Handling and Logging

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 7.1.1 | No credentials or session tokens in logs | implemented | src/Shared/Infrastructure/Logging/Redactor.php applied to logs and audit |
| 7.1.2 | No other sensitive data in logs | implemented | Redactor patterns (PINFL, cards, JWT, Bearer) |
| 7.1.3 | Security events logged | implemented | AuditLogger::security (auth.*, security.*), Security Center events |
| 7.1.4 | Each log event has necessary information | implemented | audit_log: actor, IP, user agent, correlation id, before/after |
| 7.2.1 | All authentication decisions logged | implemented | login_attempts + audit auth.* events |
| 7.2.2 | All access control failures logged | implemented | AccessService::assert audits denials (result=denied) |
| 7.3.1 | Log injection protection | implemented | structured JSON logs (JsonStreamTarget) |
| 7.3.3 | Logs protected from tampering | implemented | HMAC-signed append-only audit_log (trigger), AuditVerifier, admin.audit.partitions |
| 7.3.4 | Time sources synchronized | partial | containers use host NTP; PostgreSQL timezone UTC |
| 7.4.1 | Generic error message with reference id | implemented | resources/views/errors/*.php show "Ref: <correlation id>" |
| 7.4.2 | Exception handling across the codebase | implemented | DomainErrorMiddleware + yiisoft/error-handler |

## V8 Data Protection

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 8.1.1 | Sensitive data not cached in server components | implemented | Cache-Control no-store on exports/backups (AuditController::export, BackupsController::download) |
| 8.1.2 | Cached copies protected / purged | implemented | FailSafeCache keys without personal data |
| 8.1.5 | Regular backups of important data | implemented | BackupService (pg_dump to S3) + weekly restore verification; tests/Integration/OpsBackupRestoreTest.php |
| 8.1.6 | Backups stored securely | implemented | private S3 bucket, sha256 checksum, download requires backup.manage and is audited |
| 8.2.1 | Anti-caching headers for sensitive data | partial | no-store on downloads; HTML pages rely on private session cookies |
| 8.2.2 | No sensitive data in browser storage | implemented | only theme preference in localStorage (assets/admin/app.js) |
| 8.3.1 | Sensitive data in body/headers, not URL | implemented | credentials only via POST; tokens never in query strings |
| 8.3.4 | Sensitive data identified and policy defined | partial | Redactor key list; formal data protection policy is organisational |
| 8.3.5 | Access to sensitive data audited | implemented | audit.exported, backup.downloaded records (category security) |
| 8.3.8 | Retention policy enforced | implemented | MaintenanceJobs::cleanup, retention.* settings, backup retention (BackupService::purgeExpired) |

## V9 Communication

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 9.1.1 | TLS for all client connectivity | partial | HSTS + HTTPS in production ingress; dev stack is plain HTTP (posture check "https") |
| 9.1.2 | Strong cipher suites | partial | terminated at the ingress controller (cluster configuration) |
| 9.2.1 | Trusted TLS certificates for outbound connections | implemented | Guzzle verify=true for GitLab/Telegram/OIDC; Kubernetes client verifies against cluster CA (OpsClients) |
| 9.2.2 | TLS for all outbound connections | partial | external APIs over HTTPS; internal dev services (Prometheus, S3) over HTTP |
| 9.2.4 | Certificate revocation checking | n/a | delegated to system trust store |

## V10 Malicious Code

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 10.2.1 | No unauthorized phone-home / data collection | implemented | outbound calls only to configured integrations |
| 10.2.3 | No back doors / undocumented functions | implemented | code review; Gii disabled in production (posture check "gii") |
| 10.3.1 | Auto-update over secure channel | n/a | images built by CI, no in-app auto-update |
| 10.3.2 | Integrity protection of code | partial | immutable container images; image signing not configured |
| 10.3.3 | Subdomain takeover protection | n/a | DNS is managed outside the application |

## V11 Business Logic

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 11.1.1 | Business flows processed in sequential order | implemented | state machines: IncidentService::TRANSITIONS, deployment statuses, workflow transitions |
| 11.1.2 | Business limits per user / realistic timing | implemented | rate limits (RedisRateLimiter), login throttling |
| 11.1.3 | Limits on high-value actions | implemented | four-eyes approval, step-up re-authentication |
| 11.1.4 | Anti-automation for excessive calls | implemented | API rate limit per client (api.rate_limit_per_minute) |
| 11.1.5 | Business logic limits validated | implemented | e.g. replicas 0–100 (KubernetesClient::MAX_REPLICAS) |
| 11.1.6 | No TOCTOU / race conditions in flows | implemented | SELECT … FOR UPDATE in DeploymentService/IncidentService, unique index on open incident fingerprint |
| 11.1.7 | Monitoring of unusual activity | implemented | login failure metrics, Security Center failures by IP/account |
| 11.1.8 | Configurable alerting on attacks | partial | Prometheus alert rules via monitoring profile; Telegram incident alerts |

## V12 Files and Resources

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 12.1.1 | Large file uploads rejected | implemented | upload_max_filesize / post_max_size (infra/docker/php/conf.d/platform.ini) |
| 12.1.3 | File size quota per user | partial | per-request limits only |
| 12.3.1 | User-submitted filenames not used directly | implemented | stored_files.path generated; Content-Disposition sanitised (Responder::download) |
| 12.3.2 | File metadata validated against path traversal | implemented | object keys generated server-side (BackupService::run) |
| 12.4.1 | Untrusted files stored outside the web root | implemented | S3 object storage (ObjectStorage), private visibility |
| 12.5.1 | Web tier serves only allowed file types | implemented | nginx serves /public static assets only |
| 12.5.2 | Uploaded files not executed | implemented | uploads go to S3, never to the PHP document root |
| 12.6.1 | SSRF allow-list for server-side requests | partial | targets come from admin settings; no network egress policy in dev |

## V13 API and Web Service

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 13.1.1 | Same encoding/parsers across components | implemented | UTF-8 JSON everywhere |
| 13.1.3 | API URLs do not expose sensitive information | implemented | tokens in Authorization header only |
| 13.1.4 | Authorization decisions at URI and resource level | implemented | route middleware + service-level checks |
| 13.1.5 | Requests with unexpected content types rejected | partial | JSON body parser for API; strict content-type enforcement per endpoint pending |
| 13.2.1 | Enabled RESTful HTTP methods valid for the user | implemented | method-specific routes (MethodNotAllowed for others) |
| 13.2.2 | JSON schema validation | partial | validation in application services; OpenAPI spec published (src/Api/V1/OpenApi) |
| 13.2.3 | CSRF protection for cookie-based REST | implemented | API is stateless/cookie-free (config/web/di/application.php) |
| 13.2.5 | Content-Type checked on incoming requests | partial | see 13.1.5 |
| 13.3.1 | SOAP/XML schema validation | n/a | no SOAP services |
| 13.4.1 | GraphQL query limits | n/a | no GraphQL |

## V14 Configuration

| ID | Requirement | Status | Evidence |
|---|---|---|---|
| 14.1.1 | Build and deploy processes automated and repeatable | partial | Dockerfile multi-stage, GitLab pipeline triggered by DeploymentService; CI config owned by DevOps |
| 14.1.2 | Compiler/hardening flags | n/a | interpreted PHP; OPcache in production |
| 14.1.3 | Server configuration hardened | implemented | infra/docker/php/conf.d/prod.ini, expose_php off (posture check) |
| 14.1.5 | Integrity of security-relevant configuration verified | partial | settings versions + audit (SettingsService::set); drift detection not automated |
| 14.2.1 | Components up to date | partial | composer.lock pinned; automated dependency scanning not configured |
| 14.2.2 | Unneeded features removed | implemented | Gii and dev tools unavailable in production |
| 14.2.3 | Assets from trusted sources / SRI | implemented | all assets self-hosted (CSP default-src 'self') |
| 14.2.4 | Third-party components from trusted repositories | implemented | Packagist via composer.lock |
| 14.3.2 | Debug modes disabled in production | implemented | Environment::prepare forces APP_DEBUG off in prod; posture check "debug" |
| 14.3.3 | HTTP headers do not expose version information | implemented | X-Powered-By removed (AuthFlowTest::testLoginPageRendersWithSecurityHeaders) |
| 14.4.1 | Content-Type with charset on responses | implemented | text/html; charset=utf-8, application/json; charset=utf-8 |
| 14.4.2 | Content-Disposition: attachment for downloads | implemented | Responder::download, audit CSV export, backup download |
| 14.4.3 | Content-Security-Policy | implemented | SecurityHeadersMiddleware + params security.csp (no unsafe-inline) |
| 14.4.4 | X-Content-Type-Options: nosniff | implemented | SecurityHeadersMiddleware |
| 14.4.5 | Strict-Transport-Security | implemented | SecurityHeadersMiddleware (HTTPS requests) |
| 14.4.6 | Referrer-Policy | implemented | SecurityHeadersMiddleware strict-origin-when-cross-origin |
| 14.4.7 | Frame protection | implemented | X-Frame-Options DENY + frame-ancestors 'none' |
| 14.5.1 | Only required HTTP methods accepted | implemented | router method matching |
| 14.5.2 | Origin header not used for authentication | implemented | session/CSRF based |
| 14.5.3 | CORS allow-list | implemented | setting api.cors_origins (exact list, default empty) |

— Author: ShokirjonMK
