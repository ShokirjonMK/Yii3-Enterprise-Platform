<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Channel;

use App\Platform\Integrations\Exception\IntegrationException;
use App\Platform\Integrations\Telegram\TelegramBotClient;
use App\Platform\Notifications\NotificationChannelInterface;
use App\Platform\Queue\PermanentJobFailure;
use App\Platform\Settings\SettingsService;
use Throwable;

/**
 * Telegram Bot API delivery. Bot token: setting `notifications.telegram_bot_token` (secret, env TELEGRAM_BOT_TOKEN);
 * recipient: the user's chat id (user-scoped setting notifications.telegram_chat_id, resolved by Notifier).
 * 400/401/403/404 from Telegram (bad chat id, bot blocked, invalid token) are permanent; 429/5xx/network are retried.
 */
final class TelegramChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TelegramBotClient $client,
    ) {}

    public function code(): string
    {
        return 'telegram';
    }

    public function send(array $notification): ?string
    {
        $token = $this->token();
        if ($token === null) {
            throw new PermanentJobFailure('Telegram is not configured (notifications.telegram_bot_token).');
        }
        $chat = trim((string) $notification['recipient']);
        if (preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{5,64})$/', $chat) !== 1) {
            throw new PermanentJobFailure('Invalid Telegram chat id.');
        }
        $subject = trim((string) ($notification['subject'] ?? ''));
        $text = ($subject !== '' ? '<b>' . TelegramBotClient::escape($subject) . "</b>\n\n" : '') . TelegramBotClient::escape((string) $notification['body']);
        try {
            return $this->client->sendMessage($token, $chat, $text);
        } catch (IntegrationException $e) {
            if (!$e->isRetryable()) {
                throw new PermanentJobFailure($e->getMessage(), 0, $e);
            }
            throw $e;
        }
    }

    public function health(): array
    {
        $token = $this->token();
        if ($token === null) {
            return ['status' => 'unconfigured', 'details' => ['missing' => 'notifications.telegram_bot_token']];
        }
        try {
            $me = $this->client->getMe($token);
            return ['status' => 'healthy', 'details' => ['bot' => '@' . ($me['username'] ?? '?')]];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'details' => ['error' => $e->getMessage()]];
        }
    }

    private function token(): ?string
    {
        $t = trim((string) ($this->settings->get('notifications.telegram_bot_token') ?? ''));
        return $t === '' ? null : $t;
    }
}
