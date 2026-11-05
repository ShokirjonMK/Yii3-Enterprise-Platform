<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Platform\Identity\Mfa\TelegramLink;
use App\Platform\Settings\SettingsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Telegram Bot API webhook (Admin → Authentication → Two-factor → Telegram → "Use webhook").
 * Telegram sends the secret chosen at setWebhook in X-Telegram-Bot-Api-Secret-Token; anything else gets 403.
 * Always answers 200 quickly for valid requests (Telegram retries non-2xx responses).
 *
 * Author: ShokirjonMK
 */
final class TelegramWebhookController
{
    public function __construct(
        private readonly TelegramLink $telegram,
        private readonly SettingsService $settings,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $secret = trim((string) ($this->settings->get('mfa.telegram_webhook_secret') ?? ''));
        $given = $request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token');
        if ($secret === '' || $this->telegram->mode() !== 'webhook' || !hash_equals($secret, $given)) {
            return $this->responses->createResponse(403);
        }
        $raw = (string) $request->getBody();
        $update = strlen($raw) <= 65536 ? json_decode($raw, true) : null;
        if (is_array($update)) {
            $this->telegram->handle($update);
        }
        $response = $this->responses->createResponse(200)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write('{"ok":true}');
        return $response;
    }
}
