# Single sign-on (SSO)

Code: `src/Platform/Identity/Sso/*` (adapters + `SsoService`), admin UI **Admin → Authentication** (`admin.auth*`,
`src/Admin/Action/Authentication/AuthenticationController.php`).

## Login methods

Two feature flags control the login page:

| Flag | Effect |
|---|---|
| `auth.local_login` | Username/password sign-in. Accounts with `auth_policy = local_only` (break-glass) can always use a password. |
| `auth.sso` | Shows enabled + healthy SSO providers on the login page. |

Switches are on **Admin → Authentication → Login methods** (`POST /admin/auth/methods/{flag}`), require `auth.manage`
**and** `settings.manage` (the `FeatureFlagService` checks it) and a recent re-authentication. Guards: one method must
always stay enabled, and local login cannot be switched off until at least one SSO provider is enabled and usable.

## Provider types

| Type | Status | Required settings (`requiredConfig`) | Optional |
|---|---|---|---|
| `oidc` — OpenID Connect | TESTED with a mock IdP (`tests/Unit/OidcIdTokenValidationTest`) | `issuer`, `client_id` (+ client secret) | `discovery_url`, `scopes` (default `openid profile email`), `username_claim` (default `preferred_username`), `token_auth` (`client_secret_basic` / `client_secret_post`), `use_userinfo`, `link_by_verified_email`, `allow_insecure_http` (dev only, rejected in prod) |
| `oneid` — OneID (id.egov.uz) | **IMPLEMENTED · UNVERIFIED** | `endpoint`, `client_id`, `scope` (+ client secret) | — |
| `oauth2` — generic OAuth 2.0 | IMPLEMENTED | `authorize_url`, `token_url`, `userinfo_url`, `client_id`, `issuer` (+ client secret) | `scope`, `subject_claim` (`sub`), `email_claim` (`email`), `name_claim` (`name`), `username_claim` (`login`), `allow_insecure_http` |

OIDC implements discovery, PKCE S256, `state`, `nonce`, JWKS signature verification and `iss`/`aud`/`azp`/`exp` checks.
All endpoint URLs must be `https://` (except `allow_insecure_http` outside production).

### OneID — UNVERIFIED

OneID is an OAuth 2.0–style protocol with custom grant types (no ID token, nonce, PKCE or JWKS):
`response_type=one_code` → `grant_type=one_authorization_code` → `grant_type=one_access_token_identify`, all against a
single endpoint. The linking subject is the PINFL (`pin`, 14 digits); stored claims have the PINFL masked.
OneID never asserts a verified e-mail, so `link_by_verified_email` does not apply.

It has **not** been verified against the live service. To verify, the operator must issue per organisation:

1. the exact **endpoint URL** (from the integration agreement — nothing is prefilled or hard-coded),
2. **client_id** and **client secret**,
3. the **scope** value,
4. registration of the callback URL `https://<APP_BASE_URL>/auth/sso/<code>/callback`.

"Test connection" for OneID checks reachability of the endpoint only; a real sign-in with issued credentials is
required before the status can be changed to VERIFIED.

## Administration (section 03)

| Action | Route | Permission | Re-auth |
|---|---|---|---|
| List providers, login methods, recent changes | `GET /admin/auth` (`admin.auth`) | `auth.view` | — |
| Provider details, health, audit history | `GET /admin/auth/providers/{code}` | `auth.view` | — |
| Create (form per type `?type=oidc|oneid|oauth2`) | `GET/POST /admin/auth/new-provider` | `auth.manage` | POST |
| Edit (incl. secret change) | `GET/POST /admin/auth/providers/{code}/edit` | `auth.manage` | POST |
| Enable / disable | `POST …/enable`, `…/disable` | `auth.manage` | yes |
| Test connection (updates `status`, `last_health_at`, `last_health_error`) | `POST …/test` | `auth.manage` | — |
| Delete | `POST …/delete` | `auth.manage` | yes |

- New/edited providers with missing required settings are stored as `unconfigured` ("Not configured") and cannot be
  enabled; a provider whose last test failed cannot be enabled until a test succeeds.
- The form only accepts the documented config keys for the provider type; anything else is dropped.
- **The client secret is write-only.** It is encrypted with `Encrypter` (purpose `sso`) into `sso_providers.secret_enc`,
  never rendered (the UI shows only *set / not set*), never written to audit (`secret_changed: true|false` only).
  Leaving the field empty keeps the stored secret.
- Auto-provisioning creates SSO-only accounts with the provider's default role; administrative roles are refused.
- A provider can be bound to a tenant; actors without `tenants.cross` only see global providers and providers of their
  own tenant.
- Audit actions: `sso.provider.created|updated|enabled|disabled|tested|deleted`, `sso.identity.unlinked`,
  `auth.sso.failure` (per provider, shown as "Sign-in failures (24 h)").

## Account linking

1. Existing link (`issuer` + `subject` in `user_identities`) → that user.
2. Else, if `link_by_verified_email` and the IdP asserts `email_verified=true` and exactly that e-mail exists → link.
3. Else, if auto-provisioning is on → new SSO-only user in the provider's (or default) tenant.
4. Else → refused ("account not linked").

`local_only` users can never sign in via SSO; tenant-bound providers refuse users of other tenants.
Linked identities are listed per user on **Admin → Users → user** (subject masked) and can be unlinked
(`users.manage` + re-auth; administrative accounts only by a superadmin).

— Author: ShokirjonMK
