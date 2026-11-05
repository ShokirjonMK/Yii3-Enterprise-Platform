<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

use App\Platform\Audit\AuditLogger;
use App\Platform\Integrations\Telegram\TelegramBotClient;
use App\Platform\Settings\SettingsService;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * "Connect Telegram" with one button:
 *
 *   1. the signed-in user presses the button → a single-use token (10 min, Redis) and a deep link
 *      https://t.me/<bot>?start=<token> are created,
 *   2. Telegram opens the bot, the user presses START → the bot receives "/start <token>",
 *   3. the update (webhook or getUpdates polling) binds the private chat id to the account and the bot replies,
 *   4. the browser, polling the link status, continues automatically.
 *
 * Only private chats are accepted. A chat already bound to another account is moved (the latest owner of the
 * Telegram account wins; both events are audited). Polling mode needs no public URL (works on localhost).
 *
 * Author: ShokirjonMK
 */
final class TelegramLink
{
    private const TTL = 600;

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly SettingsService $settings,
        private readonly TelegramBotClient $bot,
        private readonly MfaMethodRepository $methods,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array{token:string, url:string, bot:string} */
    public function start(string $userId): array
    {
        $bot = $this->botUsername();
        $old = $this->redis->get("mfa:tg:user:$userId");
        if (is_string($old)) {
            $this->redis->del(["mfa:tg:link:$old"]);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->redis->setex("mfa:tg:link:$token", self::TTL, $userId);
        $this->redis->setex("mfa:tg:user:$userId", self::TTL, $token);
        return ['token' => $token, 'url' => 'https://t.me/' . $bot . '?start=' . $token, 'bot' => $bot];
    }

    /** "pending" | "linked" | "expired" */
    public function status(string $userId): string
    {
        if ($this->mode() === 'polling') {
            $this->pollOnce();
        }
        if ($this->redis->get("mfa:tg:done:$userId") !== null) {
            $this->redis->del(["mfa:tg:done:$userId"]);
            return 'linked';
        }
        return $this->redis->get("mfa:tg:user:$userId") !== null ? 'pending' : 'expired';
    }

    /** Fetches pending updates once (getUpdates, no long wait); at most one poller at a time. @return int processed */
    public function pollOnce(int $waitSeconds = 0): int
    {
        $token = $this->token();
        if ($token === null || $this->redis->set('mfa:tg:poll-lock', '1', 'EX', max(5, $waitSeconds + 5), 'NX') === null) {
            return 0;
        }
        try {
            $offset = (int) ($this->redis->get('mfa:tg:offset') ?? 0);
            $updates = (array) $this->bot->api($token, 'getUpdates', ['offset' => $offset, 'timeout' => $waitSeconds, 'allowed_updates' => ['message']], ($waitSeconds + 10) * 1000);
            $n = 0;
            foreach ($updates as $u) {
                $this->redis->set('mfa:tg:offset', (string) ((int) ($u['update_id'] ?? 0) + 1));
                $this->handle((array) $u);
                $n++;
            }
            return $n;
        } catch (Throwable $e) {
            $this->logger->notice('Telegram getUpdates failed', ['error' => $e->getMessage()]);
            return 0;
        } finally {
            $this->redis->del(['mfa:tg:poll-lock']);
        }
    }

    /** Processes one Bot API update (from the webhook or from polling). */
    public function handle(array $update): void
    {
        $msg = (array) ($update['message'] ?? []);
        $chat = (array) ($msg['chat'] ?? []);
        $text = trim((string) ($msg['text'] ?? ''));
        if ($chat === [] || !str_starts_with($text, '/start')) {
            return;
        }
        $chatId = (string) ($chat['id'] ?? '');
        if (($chat['type'] ?? '') !== 'private' || preg_match('/^\d{1,20}$/', $chatId) !== 1) {
            $this->reply($chatId, 'This bot works only in a private chat.');
            return;
        }
        $token = trim(substr($text, 6));
        if ($token === '') {
            $this->reply($chatId, "👋 Hello! To connect this Telegram account, press <b>Connect Telegram</b> in your account security settings on the platform.");
            return;
        }
        $userId = preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) === 1 ? $this->redis->get("mfa:tg:link:$token") : null;
        if (!is_string($userId)) {
            $this->reply($chatId, '⚠️ This link has expired. Press <b>Connect Telegram</b> on the platform again.');
            return;
        }
        $this->redis->del(["mfa:tg:link:$token", "mfa:tg:user:$userId"]);
        $from = (array) ($msg['from'] ?? []);
        $username = (string) ($from['username'] ?? '');
        $label = $username !== '' ? '@' . $username : trim(((string) ($from['first_name'] ?? '')) . ' ' . ((string) ($from['last_name'] ?? '')));
        $previous = $this->methods->ownerOf(MfaMethodType::TELEGRAM, $chatId);
        $this->methods->save($userId, MfaMethodType::TELEGRAM, $chatId, $label ?: 'Telegram', ['username' => $username], hint: $label ?: 'Telegram');
        $this->audit->security('auth.mfa.method_added', 'success', ['method' => 'telegram', 'moved_from_other_account' => $previous !== null && $previous !== $userId], 'notice', $userId);
        $this->redis->setex("mfa:tg:done:$userId", self::TTL, '1');
        $this->reply($chatId, '✅ Telegram is connected. Sign-in codes will be sent to this chat.');
    }

    public function mode(): string
    {
        return (string) ($this->settings->get('mfa.telegram_update_mode') ?: 'polling');
    }

    public function botUsername(): string
    {
        $configured = ltrim(trim((string) ($this->settings->get('mfa.telegram_bot_username') ?? '')), '@');
        if ($configured !== '') {
            return $configured;
        }
        $token = $this->token() ?? throw new RuntimeException('Telegram bot is not configured (notifications.telegram_bot_token).');
        $cacheKey = 'mfa:tg:botname:' . substr(hash('sha256', $token), 0, 16);
        $cached = $this->redis->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $me = $this->bot->getMe($token);
        $name = (string) ($me['username'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Telegram getMe returned no bot username.');
        }
        $this->redis->setex($cacheKey, 86400, $name);
        return $name;
    }

    /** Registers the webhook (mode "webhook"): POST <url> with header X-Telegram-Bot-Api-Secret-Token. */
    public function setWebhook(string $url, string $secret): void
    {
        $token = $this->token() ?? throw new RuntimeException('Telegram bot is not configured.');
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Telegram requires a public HTTPS webhook URL.');
        }
        $this->bot->api($token, 'setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message'], 'drop_pending_updates' => true]);
    }

    public function deleteWebhook(): void
    {
        $token = $this->token() ?? throw new RuntimeException('Telegram bot is not configured.');
        $this->bot->api($token, 'deleteWebhook', ['drop_pending_updates' => false]);
    }

    /** @return array<string,mixed> */
    public function webhookInfo(): array
    {
        $token = $this->token();
        return $token === null ? [] : (array) $this->bot->api($token, 'getWebhookInfo');
    }

    private function reply(string $chatId, string $html): void
    {
        $token = $this->token();
        if ($token === null || $chatId === '') {
            return;
        }
        try {
            $this->bot->sendMessage($token, $chatId, $html);
        } catch (Throwable $e) {
            $this->logger->notice('Telegram reply failed', ['error' => $e->getMessage()]);
        }
    }

    private function token(): ?string
    {
        $t = trim((string) ($this->settings->get('notifications.telegram_bot_token') ?? ''));
        return $t === '' ? null : $t;
    }
}
