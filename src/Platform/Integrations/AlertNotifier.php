<?php

declare(strict_types=1);

namespace App\Platform\Integrations;

use App\Platform\Integrations\Telegram\TelegramBotClient;
use App\Platform\Settings\SettingsService;
use App\Shared\Infrastructure\Logging\Redactor;
use App\Shared\Infrastructure\RateLimit\RedisRateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Operational alerts for platform operators (circuit opened, backup failed, incident sev1 …).
 *
 *   primary:  Telegram Bot API sendMessage to `notifications.telegram_alert_chat`
 *   fallback: e-mail to `app.support_email` when Telegram is not configured or the send fails
 *
 * Identical alerts (same fingerprint) are suppressed for 5 minutes (Redis) to avoid alert storms.
 * Never throws — alerting must not break the code path that raised the alert.
 */
final class AlertNotifier
{
    private const DEDUPE_SECONDS = 300;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly TelegramBotClient $telegram,
        private readonly TransportInterface $mailer,
        private readonly RedisRateLimiter $limiter,
        private readonly LoggerInterface $logger,
        private readonly string $from = 'platform@localhost',
    ) {}

    /**
     * @param array<string,scalar|null> $context
     * @return array{delivered:bool, channel:?string, suppressed:bool, errors:list<string>}
     */
    public function alert(string $title, string $message, string $severity = 'warning', array $context = [], ?string $fingerprint = null): array
    {
        $fingerprint ??= hash('sha256', $severity . '|' . $title);
        $result = ['delivered' => false, 'channel' => null, 'suppressed' => false, 'errors' => []];
        if (!$this->limiter->hit('alert:' . $fingerprint, 1, self::DEDUPE_SECONDS)->allowed) {
            return ['suppressed' => true] + $result;
        }
        $context = (array) Redactor::redact($context);
        $message = Redactor::redactString($message);

        $token = (string) ($this->settings->get('notifications.telegram_bot_token') ?? '');
        $chat = trim((string) ($this->settings->get('notifications.telegram_alert_chat') ?? ''));
        if ($token !== '' && $chat !== '') {
            try {
                $icon = match ($severity) {
                    'critical' => '🔴', 'error' => '🟠', 'info' => 'ℹ️', default => '🟡'
                };
                $lines = [$icon . ' <b>' . TelegramBotClient::escape(mb_strtoupper($severity) . ': ' . $title) . '</b>', '', TelegramBotClient::escape($message)];
                foreach ($context as $k => $v) {
                    $lines[] = '• <code>' . TelegramBotClient::escape((string) $k) . '</code>: ' . TelegramBotClient::escape(is_scalar($v) ? (string) $v : (string) json_encode($v));
                }
                $this->telegram->sendMessage($token, $chat, implode("\n", $lines));
                return ['delivered' => true, 'channel' => 'telegram'] + $result;
            } catch (Throwable $e) {
                $result['errors'][] = 'telegram: ' . $e->getMessage();
            }
        } else {
            $result['errors'][] = 'telegram: not configured';
        }

        $to = trim((string) ($this->settings->get('app.support_email') ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $result['errors'][] = 'email: app.support_email not configured';
            $this->logger->error('Platform alert could not be delivered', ['title' => $title, 'severity' => $severity, 'errors' => $result['errors']]);
            return $result;
        }
        try {
            $body = $message . "\n\n";
            foreach ($context as $k => $v) {
                $body .= $k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v)) . "\n";
            }
            $this->mailer->send((new Email())->from(Address::create($this->from))->to($to)
                ->subject('[' . strtoupper($severity) . '] ' . mb_substr($title, 0, 200))->text($body));
            return ['delivered' => true, 'channel' => 'email'] + $result;
        } catch (Throwable $e) {
            $result['errors'][] = 'email: ' . $e->getMessage();
            $this->logger->error('Platform alert could not be delivered', ['title' => $title, 'severity' => $severity, 'errors' => $result['errors']]);
            return $result;
        }
    }
}
