# Messaging & Notifications (Admin section 08) — channels, templates, deliveries, broadcast

Delivery core (unchanged, platform): `Notifier::notify()` stores one `notifications` row per channel; non-in-app channels
are delivered by job `platform.notifications.deliver` (queue `notifications`, 6 attempts, backoff) through the adapter
registered in `config/common/notification-channels/*.php`.

## Channel adapters (integration work package)

| Channel | Adapter | Configuration (Admin → System Settings → Notifications) | Recipient | `health()` |
|---|---|---|---|---|
| telegram | `TelegramChannel` → `TelegramBotClient` (Bot API `sendMessage`, `parse_mode=HTML`, text escaped) | `notifications.telegram_bot_token` (secret, env `TELEGRAM_BOT_TOKEN`) | user setting `notifications.telegram_chat_id` | `getMe` → healthy (bot name) / failed; no token → unconfigured |
| sms | `SmsChannel` — generic HTTPS gateway: `POST <url>` JSON `{to, text, from}`, `Authorization: Bearer <token>`; message id from `id`/`message_id`/`messageId`/`data.id` | `notifications.sms_gateway_url` (https only), `notifications.sms_gateway_token` (secret), `notifications.sms_sender` | `users.phone` (E.164, normalised) | configured + public (SSRF guard) + TLS reachable → healthy (delivery itself is verified only by a test send) |
| webpush | `WebPushChannel` (minishlink/web-push 9, VAPID, aes128gcm) to every subscription of the user; 404/410 → subscription deleted | `notifications.vapid_public_key`, `notifications.vapid_private_key` (secret); subject `mailto:<app.support_email>` | user id → `push_subscriptions` | keys missing → unconfigured; malformed → failed; valid → healthy (+ subscription count) |

All HTTP goes through the platform `HttpClient` (Telegram/SMS) — breaker per provider (`telegram`, `sms`), SSRF guard,
redacted `integration_logs` (the bot token in the URL path is masked). Error mapping: provider 400/401/403/404 and invalid
recipients → `PermanentJobFailure` (no retries); 429/5xx/network → retried by the queue.

Generate VAPID keys: `php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'`.

## Web Push subscriptions (end-user endpoints, `config/routes/web/push.php`)

Inside the authenticated web group (session + CSRF header `X-CSRF-Token`):

| Method & path | Body | Response |
|---|---|---|
| `GET /notifications/push/key` | — | `{"publicKey": "<VAPID public key>" \| null}` |
| `POST /notifications/push/subscribe` | `PushSubscription.toJSON()` | `201 {"id": "..."}` · `422` invalid keys / non-HTTPS / blocked endpoint |
| `POST /notifications/push/unsubscribe` | `{"endpoint": "..."}` | `{"removed": n}` |

Browser side (not shipped — the web UI team adds it as a static asset, strict CSP forbids inline JS):

```js
const reg = await navigator.serviceWorker.register('/sw.js');
const { publicKey } = await (await fetch('/notifications/push/key')).json();
const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: publicKey });
await fetch('/notifications/push/subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json',
  'X-CSRF-Token': document.querySelector('meta[name=csrf]').content }, body: JSON.stringify(sub) });
// sw.js: self.addEventListener('push', e => { const d = e.data.json(); e.waitUntil(self.registration.showNotification(d.title, { body: d.body, data: d.url })); });
```

## Admin UI (`/admin/notifications`)

* **Channels** — enabled/health/last error, 24 h delivery stats per status; *Check* (HTMX, runs the adapter health and
  stores the status), *Enable/Disable* (`notifications.manage`; enabling an unconfigured channel is refused, in-app cannot
  be disabled), *Test to me* (sends template `platform.test` to the acting admin through that channel).
* **Templates** — CRUD per code/channel/locale (uz, ru, en), live preview while typing (draft substitution) and the stored
  effective rendering via `Notifier::render` (fallback chain channel/locale → en → inapp).
* **Deliveries** — filters (status, channel, template, recipient/subject/user, date range), detail with redacted
  parameters, *Retry* of failed rows (re-enqueue `platform.notifications.deliver`, fresh idempotency key), *Retry all failed* (≤ 500).
* **Broadcast** (`notifications.send`) — role (direct or inherited via parent roles), template code or subject/message
  (template `platform.broadcast`), channels; own tenant only without `tenants.cross`; max 10 000 users; audited
  (`notifications.broadcast`).

Seeded templates: `platform.test`, `platform.broadcast` (migration `M261104040003NotificationTemplates`).

## Status

| Item | Status | Evidence |
|---|---|---|
| Telegram / SMS / Web Push adapters + honest health | TESTED (mock providers) | `tests/Integration/ChannelsProvidersTest` |
| Push subscription endpoints | TESTED | `tests/E2E/Admin/NotificationsAdminTest::testPushSubscriptionEndpoint` |
| Admin channels/templates/deliveries/broadcast | TESTED | `tests/E2E/Admin/NotificationsAdminTest` |
| Real Telegram bot / SMS provider / browser push | UNVERIFIED | need bot token + chat id, gateway URL/token, VAPID keys + a browser service worker |
| Queue workers pick up the new adapters | requires `worker` restart (handler/channel maps are loaded at worker start) |

— Author: ShokirjonMK
