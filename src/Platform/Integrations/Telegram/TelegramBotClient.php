<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Telegram;

use App\Platform\Integrations\Exception\ClientErrorException;
use App\Platform\Integrations\Exception\IntegrationException;
use App\Platform\Integrations\Http\HttpClient;
use App\Platform\Integrations\Http\IntegrationConfig;
use SensitiveParameter;

/**
 * Minimal Telegram Bot API client (sendMessage, getMe) on top of the platform HttpClient — inherits SSRF guard,
 * timeouts, circuit breaker ("telegram"), logging (the bot token in the URL path is masked) and metrics.
 * sendMessage is POST and is not retried by the client (Telegram has no idempotency key): the queue job retries.
 */
final class TelegramBotClient
{
    public const BASE_URL = 'https://api.telegram.org';

    public function __construct(private readonly HttpClient $http) {}

    /**
     * @return string Telegram message_id
     * @throws IntegrationException
     */
    public function sendMessage(#[SensitiveParameter] string $token, string $chatId, string $html, array $mask = [], ?array $replyMarkup = null): string
    {
        $data = $this->http->sendJson($this->config(), 'POST', '/bot' . $token . '/sendMessage', [
            'json' => ['chat_id' => $chatId, 'text' => mb_substr($html, 0, 4096), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]
                + ($replyMarkup !== null ? ['reply_markup' => $replyMarkup] : []),
            'secrets' => [$token, ...$mask],
        ]);
        if (($data['ok'] ?? false) !== true) {
            throw new ClientErrorException('Telegram API error: ' . mb_substr((string) ($data['description'] ?? 'unknown'), 0, 200), 'client_error', null, 'telegram');
        }
        return (string) ($data['result']['message_id'] ?? '');
    }

    /** @return array<string,mixed> bot info (id, username) */
    public function getMe(#[SensitiveParameter] string $token): array
    {
        $data = $this->http->sendJson($this->config()->withRetries(0, 0), 'GET', '/bot' . $token . '/getMe', ['secrets' => [$token]]);
        return (array) ($data['result'] ?? []);
    }

    /**
     * Any Bot API method (getUpdates, setWebhook, deleteWebhook, getWebhookInfo, answerCallbackQuery…).
     *
     * @param array<string,mixed> $params
     * @return mixed the "result" field
     */
    public function api(#[SensitiveParameter] string $token, string $method, array $params = [], int $timeoutMs = 10000): mixed
    {
        if (preg_match('/^[A-Za-z]{3,40}$/', $method) !== 1) {
            throw new \InvalidArgumentException('Invalid Telegram method.');
        }
        $data = $this->http->sendJson(IntegrationConfig::adhoc('telegram', self::BASE_URL, $timeoutMs, 0), 'POST', '/bot' . $token . '/' . $method, [
            'json' => (object) $params,
            'secrets' => array_values(array_filter([$token, is_string($params['secret_token'] ?? null) ? $params['secret_token'] : null])),
        ]);
        if (($data['ok'] ?? false) !== true) {
            throw new ClientErrorException('Telegram API error: ' . mb_substr((string) ($data['description'] ?? 'unknown'), 0, 200), 'client_error', null, 'telegram');
        }
        return $data['result'] ?? null;
    }

    /** Escapes text for parse_mode=HTML. */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function config(): IntegrationConfig
    {
        return IntegrationConfig::adhoc('telegram', self::BASE_URL, 10000, 2);
    }
}
