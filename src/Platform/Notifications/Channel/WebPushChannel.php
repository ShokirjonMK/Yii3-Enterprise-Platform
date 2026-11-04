<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Channel;

use App\Platform\Integrations\Push\PushSubscriptions;
use App\Platform\Notifications\NotificationChannelInterface;
use App\Platform\Queue\PermanentJobFailure;
use App\Platform\Security\UrlGuard;
use App\Platform\Settings\SettingsService;
use GuzzleHttp\HandlerStack;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Throwable;

/**
 * Browser push (RFC 8030 + VAPID RFC 8292) via minishlink/web-push. Keys: settings notifications.vapid_public_key /
 * notifications.vapid_private_key (secret). Recipient is the user id (Notifier); the message is sent to every
 * subscription of that user (push_subscriptions). Expired subscriptions (404/410) are deleted.
 * Each endpoint is re-checked by the SSRF guard before sending.
 */
final class WebPushChannel implements NotificationChannelInterface
{
    /** @var callable|null */
    private $handler;

    /** @param callable|null $handler Guzzle handler (tests) */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly PushSubscriptions $subscriptions,
        private readonly UrlGuard $guard,
        ?callable $handler = null,
    ) {
        $this->handler = $handler;
    }

    public function code(): string
    {
        return 'webpush';
    }

    public function send(array $notification): ?string
    {
        $keys = $this->keys();
        if ($keys === null) {
            throw new PermanentJobFailure('Web Push is not configured (VAPID keys).');
        }
        $subs = $this->subscriptions->forUser((string) $notification['recipient']);
        if ($subs === []) {
            throw new PermanentJobFailure('The user has no push subscriptions.');
        }
        $payload = (string) json_encode([
            'title' => (string) ($notification['subject'] ?? ''),
            'body' => mb_substr((string) $notification['body'], 0, 1000),
            'id' => (string) ($notification['id'] ?? ''),
            'url' => '/notifications',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $push = $this->client($keys);
        $sent = 0;
        $errors = [];
        $transient = false;
        foreach ($subs as $s) {
            try {
                $this->guard->assertAllowed((string) $s['endpoint']);
            } catch (Throwable $e) {
                $this->subscriptions->remove((string) $s['id']);
                $errors[] = 'blocked endpoint removed';
                continue;
            }
            $report = $push->sendOneNotification(
                Subscription::create(['endpoint' => $s['endpoint'], 'publicKey' => $s['p256dh'], 'authToken' => $s['auth'], 'contentEncoding' => $s['content_encoding']]),
                $payload,
                ['TTL' => 86400, 'urgency' => 'normal'],
            );
            if ($report->isSuccess()) {
                $sent++;
                $this->subscriptions->markUsed((string) $s['id']);
            } elseif ($report->isSubscriptionExpired()) {
                $this->subscriptions->remove((string) $s['id']);
                $errors[] = 'expired subscription removed';
            } else {
                $code = $report->getResponse()?->getStatusCode();
                $transient = $transient || $code === null || $code === 429 || $code >= 500;
                $this->subscriptions->markFailed((string) $s['id'], mb_substr($report->getReason(), 0, 500));
                $errors[] = 'HTTP ' . ($code ?? 'network') . ': ' . mb_substr($report->getReason(), 0, 200);
            }
        }
        if ($sent === 0) {
            $msg = 'Web Push delivery failed: ' . implode('; ', array_unique($errors));
            throw $transient ? new RuntimeException($msg) : new PermanentJobFailure($msg);
        }
        return 'push:' . $sent . '/' . count($subs);
    }

    public function health(): array
    {
        $public = trim((string) ($this->settings->get('notifications.vapid_public_key') ?? ''));
        $private = trim((string) ($this->settings->get('notifications.vapid_private_key') ?? ''));
        if ($public === '' || $private === '') {
            return ['status' => 'unconfigured', 'details' => ['missing' => $public === '' ? 'notifications.vapid_public_key' : 'notifications.vapid_private_key']];
        }
        if (strlen(PushSubscriptions::b64urlDecode($public)) !== 65 || strlen(PushSubscriptions::b64urlDecode($private)) !== 32) {
            return ['status' => 'failed', 'details' => ['error' => 'VAPID keys must be base64url P-256 keys (65-byte public, 32-byte private)']];
        }
        try {
            $this->client(['publicKey' => $public, 'privateKey' => $private]);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'details' => ['error' => $e->getMessage()]];
        }
        return ['status' => 'healthy', 'details' => ['subscriptions' => $this->subscriptions->count(), 'note' => 'keys valid; delivery depends on browser push services']];
    }

    /** @return array{publicKey:string, privateKey:string}|null */
    private function keys(): ?array
    {
        $public = trim((string) ($this->settings->get('notifications.vapid_public_key') ?? ''));
        $private = trim((string) ($this->settings->get('notifications.vapid_private_key') ?? ''));
        return $public === '' || $private === '' ? null : ['publicKey' => $public, 'privateKey' => $private];
    }

    /** @param array{publicKey:string, privateKey:string} $keys */
    private function client(array $keys): WebPush
    {
        $email = trim((string) ($this->settings->get('app.support_email') ?? ''));
        $subject = $email !== '' ? 'mailto:' . $email : 'mailto:platform@localhost';
        $clientOptions = ['allow_redirects' => false, 'connect_timeout' => 3];
        if ($this->handler !== null) {
            $clientOptions['handler'] = HandlerStack::create($this->handler);
        }
        return new WebPush(['VAPID' => ['subject' => $subject] + $keys], [], 10, $clientOptions);
    }
}
