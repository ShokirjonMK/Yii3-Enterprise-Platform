# Two-factor authentication (2FA)

Author: ShokirjonMK

## Methods

| Method | How it works | Provider / setup | Status |
|---|---|---|---|
| Authenticator app (TOTP) | RFC 6238, QR enrolment, replay protection | none | TESTED (E2E) |
| Passkey / security key (WebAuthn) | Touch ID / Face ID / Windows Hello / Android / YubiKey; ES256, EdDSA, RS256; signature counter (clone detection) | none (HTTPS or `localhost`) | TESTED (integration, real ES256 signatures); browser ceremony NOT RUN by automation |
| E-IMZO | the user signs a challenge from E-IMZO-SERVER in the E-IMZO desktop app; bound to PINFL (else TIN) | `mfa.eimzo_server_url` (in-house E-IMZO-SERVER), API keys `mfa.eimzo_api_keys` | IMPLEMENTED, UNVERIFIED (needs E-IMZO-SERVER + key) |
| Telegram | "Connect Telegram" button → deep link `t.me/<bot>?start=<token>` → user presses START → codes come to the chat | bot token in Notifications → Telegram; polling (no public URL) or webhook | TESTED (update handling); live bot NOT RUN (no token in dev) |
| E-mail | address is verified with a code, then codes by SMTP | Notifications → E-mail (SMTP) | TESTED (E2E through Mailpit) |
| SMS | phone verified with a code, then codes by SMS | Notifications → SMS gateway (HTTPS) | IMPLEMENTED, UNVERIFIED (needs gateway) |
| Voice call | the platform calls the number and reads the code twice (TTS) | Twilio (`mfa.voice_twilio_*`) or any HTTPS voice gateway (`mfa.voice_http_*`) | IMPLEMENTED, UNVERIFIED (needs provider) |
| Recovery codes | 10 single-use codes, created with the first factor | none | TESTED |

Users manage their methods in **Account → Security** (add, remove, make default, forget remembered devices).
Several methods can be enrolled; at sign-in the user picks one (default = last used).

## Administration (Admin → 03 Authentication → Two-factor)

- **Methods** – switch each method on/off (superadmin only), provider status, *Check / test send*.
- **Policies by role** – per role: *Required*, *allowed methods*, *remember device (0–90 days)*. `*` = everyone whose
  roles have no policy. Several roles: required if any requires it, only methods allowed by all, shortest remember
  period. Seeded: administrative roles (superadmin, platform_admin, security_admin, devops_sre) – required, only
  TOTP / passkey / E-IMZO / Telegram, no remembered devices. Policies of administrative roles (including custom roles
  flagged administrative) can be changed only by a superadmin.
- **2FA settings** – code length / lifetime / attempts / resend pause / hourly cap, Telegram mode, voice provider,
  E-IMZO server, passkey RP name.
- **Users → user → Reset 2FA** – removes all factors, recovery codes and remembered devices (lost phone).
- Every change is audited (`auth.mfa_policy.*`, `auth.mfa.method_added/removed`, `user.mfa_reset`, …).

## Security notes

- One-time codes: HMAC in Redis only, bound to purpose (login/enrol/reauth) + user + method + target, single use,
  burnt after N wrong attempts, resend cooldown and hourly cap (SMS-pumping protection), masked in integration logs.
- Delivery targets (chat id, e-mail, phone, PINFL) are encrypted; uniqueness via HMAC lookup hash – one Telegram
  chat / phone / E-IMZO holder cannot protect two accounts.
- Remembered device: random 256-bit cookie (HttpOnly, SameSite=Lax, Secure on HTTPS), only SHA-256 stored, also
  limited by the current policy period; removing a method or reset forgets all devices.
- A policy cannot leave a user locked out: if no enrolled method is allowed any more, still-enabled enrolled
  methods keep working and the user is asked to enrol an allowed one (gate `mfa_setup`).
- Step-up re-authentication (critical admin actions) = password, then any usable second factor.
- E-IMZO pages widen CSP `connect-src` only for themselves (`wss://127.0.0.1:64443 ws://127.0.0.1:64646`).
- API (`POST /api/v1/auth/token`): `mfa_code` accepts authenticator-app and recovery codes.

## Tests

- `tests/Integration/MfaMethodsTest.php` – OTP rules, policy merge, admin-role guard, Telegram linking,
  passkey register/assert with a real P-256 key, last-method protection, trusted devices.
- `tests/E2E/MfaMethodsFlowTest.php` – e-mail enrolment and sign-in via Mailpit, trusted device, step-up with the
  e-mail code, admin pages, policy enforcement, reset, webhook secret.
- `tests/E2E/AuthFlowTest.php` – existing TOTP flows (unchanged behaviour).
