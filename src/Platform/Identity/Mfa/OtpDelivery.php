<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

use App\Platform\Identity\Domain\User;
use App\Platform\Integrations\Telegram\TelegramBotClient;
use App\Platform\Notifications\Channel\EmailChannel;
use App\Platform\Notifications\Channel\SmsChannel;
use App\Platform\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Delivers a one-time code synchronously (the user is waiting on the sign-in page) through the existing
 * notification adapters — Telegram Bot API, SMTP, SMS gateway — or the voice call provider.
 * Provider errors are logged (codes are never logged) and surfaced as a generic RuntimeException.
 *
 * Author: ShokirjonMK
 */
final class OtpDelivery
{
    private const TEXT = [
        'uz' => ['subject' => 'Tasdiqlash kodi', 'body' => "{app}: tasdiqlash kodingiz {code}\nKod {min} daqiqa amal qiladi. Hech kimga bermang.", 'mail_extra' => "Agar tizimga kirishni siz boshlamagan bo'lsangiz, parolingizni almashtiring."],
        'ru' => ['subject' => 'Код подтверждения', 'body' => "{app}: ваш код подтверждения {code}\nКод действует {min} мин. Никому его не сообщайте.", 'mail_extra' => 'Если вход начали не вы — смените пароль.'],
        'en' => ['subject' => 'Verification code', 'body' => "{app}: your verification code is {code}\nThe code is valid for {min} minutes. Never share it.", 'mail_extra' => 'If you did not try to sign in, change your password.'],
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly TelegramBotClient $telegram,
        private readonly EmailChannel $email,
        private readonly SmsChannel $sms,
        private readonly VoiceCallClient $voice,
        private readonly LoggerInterface $logger,
    ) {}

    public function deliver(string $type, #[SensitiveParameter] string $target, #[SensitiveParameter] string $code, int $minutes, User $user): void
    {
        $t = self::TEXT[$user->locale] ?? self::TEXT['en'];
        $app = (string) ($this->settings->get('app.name') ?? 'Platform');
        $body = strtr($t['body'], ['{app}' => $app, '{code}' => $code, '{min}' => (string) max(1, $minutes)]);
        try {
            match ($type) {
                MfaMethodType::TELEGRAM => $this->telegram->sendMessage(
                    $this->token(),
                    $target,
                    '🔐 <b>' . TelegramBotClient::escape($app) . "</b>\n\n" . TelegramBotClient::escape($t['subject']) . ': <code>' . $code . "</code>\n\n" . TelegramBotClient::escape(explode("\n", $body)[1] ?? ''),
                    [$code],
                ),
                MfaMethodType::EMAIL => $this->email->send([
                    'id' => 'mfa-' . bin2hex(random_bytes(8)),
                    'recipient' => $target,
                    'subject' => $app . ' — ' . $t['subject'] . ': ' . $code,
                    'body' => $body . "\n\n" . $t['mail_extra'],
                ]),
                MfaMethodType::SMS => $this->sms->send(['id' => 'mfa', 'recipient' => $target, 'body' => $body, 'secrets' => [$code]]),
                MfaMethodType::VOICE => $this->voice->call($target, $code),
                default => throw new RuntimeException("Method $type does not deliver codes."),
            };
        } catch (Throwable $e) {
            $this->logger->warning('MFA code delivery failed', ['method' => $type, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            throw new RuntimeException('The code could not be sent. Try again later or choose another method.', 0, $e);
        }
    }

    private function token(): string
    {
        $token = trim((string) ($this->settings->get('notifications.telegram_bot_token') ?? ''));
        if ($token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }
        return $token;
    }
}
