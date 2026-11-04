<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use Minishlink\WebPush\VAPID;

/**
 * Provider credentials of each notification channel, edited directly in Admin → Messaging & Notifications.
 * Values are typed settings (SettingsService): versioned, audited, secrets encrypted and never shown again.
 * An environment variable (e.g. MAILER_DSN) still wins and is displayed as "set by environment".
 *
 * Author: ShokirjonMK
 */
final class ChannelSettings
{
    public const KEYS = [
        'email' => ['notifications.smtp_dsn', 'notifications.mail_from'],
        'telegram' => ['notifications.telegram_bot_token', 'notifications.telegram_alert_chat'],
        'sms' => ['notifications.sms_gateway_url', 'notifications.sms_gateway_token', 'notifications.sms_sender'],
        'webpush' => ['notifications.vapid_public_key', 'notifications.vapid_private_key'],
    ];

    public function __construct(private readonly SettingsService $settings) {}

    public function supports(string $channel): bool
    {
        return isset(self::KEYS[$channel]);
    }

    /** @return list<array{key:string, label:string, type:string, value:mixed, is_set:bool, source:string}> */
    public function fields(string $channel): array
    {
        $out = [];
        foreach (self::KEYS[$channel] ?? throw new NotFoundException('This channel has no provider settings.') as $key) {
            $def = $this->settings->definition($key);
            $value = $this->settings->get($key);
            $out[] = [
                'key' => $key,
                'label' => (string) $def['label'],
                'type' => (string) $def['type'],
                'value' => $def['type'] === 'secret' ? null : $value,
                'is_set' => $value !== null && $value !== '',
                'source' => $this->settings->source($key),
            ];
        }
        return $out;
    }

    /**
     * Secret fields: an empty input keeps the stored secret; the "clear" checkbox removes it.
     *
     * @param array<string,mixed> $input field key => value, plus "clear" => [keys]
     */
    public function save(string $actorId, string $channel, array $input): int
    {
        $changed = 0;
        $errors = [];
        $clear = (array) ($input['clear'] ?? []);
        foreach (self::KEYS[$channel] ?? throw new NotFoundException('This channel has no provider settings.') as $key) {
            $def = $this->settings->definition($key);
            $field = str_replace('.', '_', $key);
            try {
                if ($def['type'] === 'secret') {
                    if (in_array($key, $clear, true)) {
                        $this->settings->reset($actorId, $key, permission: 'notifications.manage');
                        $changed++;
                    } elseif (trim((string) ($input[$field] ?? '')) !== '') {
                        $this->settings->set($actorId, $key, trim((string) $input[$field]), comment: "channel $channel", permission: 'notifications.manage');
                        $changed++;
                    }
                    continue;
                }
                if (!array_key_exists($field, $input)) {
                    continue;
                }
                $new = trim((string) $input[$field]);
                if ($new !== (string) ($this->settings->get($key) ?? '')) {
                    $this->settings->set($actorId, $key, $new, comment: "channel $channel", permission: 'notifications.manage');
                    $changed++;
                }
            } catch (ValidationException $e) {
                foreach ($e->errors() as $msgs) {
                    $errors[$field] = $msgs;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $changed;
    }

    /** Generates a fresh VAPID key pair for Web Push (private key stored encrypted). */
    public function generateVapid(string $actorId): void
    {
        $keys = VAPID::createVapidKeys();
        $this->settings->set($actorId, 'notifications.vapid_public_key', $keys['publicKey'], comment: 'VAPID generated', permission: 'notifications.manage');
        $this->settings->set($actorId, 'notifications.vapid_private_key', $keys['privateKey'], comment: 'VAPID generated', permission: 'notifications.manage');
    }
}
