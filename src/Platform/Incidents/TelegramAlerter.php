<?php

declare(strict_types=1);

namespace App\Platform\Incidents;

use App\Platform\Settings\SettingsService;
use App\Shared\Infrastructure\Logging\Redactor;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Operational alerts to a Telegram chat (Bot API sendMessage), configured with
 * notifications.telegram_bot_token + notifications.telegram_alert_chat (or TELEGRAM_* env).
 * Best effort: alerting failures are logged and never break the caller.
 */
final class TelegramAlerter
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
        private readonly ?ClientInterface $http = null,
    ) {}

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->chat() !== '';
    }

    /** @return bool true when Telegram accepted the message */
    public function send(string $text): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        try {
            $http = $this->http ?? new Client(['timeout' => 5, 'connect_timeout' => 3, 'http_errors' => false]);
            $r = $http->request('POST', 'https://api.telegram.org/bot' . $this->token() . '/sendMessage', ['http_errors' => false, 'timeout' => 5, 'json' => [
                'chat_id' => $this->chat(),
                'text' => mb_substr($text, 0, 4000),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]]);
            if ($r->getStatusCode() !== 200) {
                $this->logger->warning('Telegram alert rejected', ['status' => $r->getStatusCode(), 'body' => Redactor::redactString(mb_substr((string) $r->getBody(), 0, 300))]);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            // The bot token is part of the URL: never log the raw exception message
            $this->logger->warning('Telegram alert failed', ['error' => str_replace($this->token(), '***', $e->getMessage())]);
            return false;
        }
    }

    public static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function token(): string
    {
        $v = $this->settings->get('notifications.telegram_bot_token');
        return is_string($v) ? trim($v) : '';
    }

    private function chat(): string
    {
        return trim((string) $this->settings->get('notifications.telegram_alert_chat'));
    }
}
