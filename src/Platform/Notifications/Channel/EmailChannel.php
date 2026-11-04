<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Channel;

use App\Platform\Notifications\NotificationChannelInterface;
use App\Platform\Queue\PermanentJobFailure;
use App\Platform\Settings\SettingsService;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * SMTP e-mail via symfony/mailer. Connection (DSN) and sender come from the settings
 * notifications.smtp_dsn / notifications.mail_from — editable in Admin → Messaging & Notifications;
 * the environment variables MAILER_DSN / MAIL_FROM override them when set.
 * Plain-text body; HTML is never built from user-controlled template variables (injection safety).
 *
 * Author: ShokirjonMK
 */
final class EmailChannel implements NotificationChannelInterface
{
    public function __construct(private readonly SettingsService $settings) {}

    public function code(): string
    {
        return 'email';
    }

    public function send(array $notification): ?string
    {
        $to = (string) $notification['recipient'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new PermanentJobFailure('Invalid e-mail recipient.');
        }
        $dsn = $this->dsn();
        if ($dsn === '' || str_starts_with($dsn, 'null://')) {
            throw new PermanentJobFailure('E-mail channel is not configured (SMTP DSN).');
        }
        $email = (new Email())
            ->from(Address::create((string) ($this->settings->get('notifications.mail_from') ?: 'noreply@platform.local')))
            ->to($to)
            ->subject((string) $notification['subject'])
            ->text((string) $notification['body']);
        $email->getHeaders()->addTextHeader('X-Notification-Id', (string) $notification['id']);
        $sent = Transport::fromDsn($dsn)->send($email);
        return $sent?->getMessageId();
    }

    public function health(): array
    {
        $dsn = $this->dsn();
        if ($dsn === '' || str_starts_with($dsn, 'null://')) {
            return ['status' => 'unconfigured', 'details' => []];
        }
        try {
            $parts = parse_url($dsn);
            $fp = @fsockopen((string) ($parts['host'] ?? ''), (int) ($parts['port'] ?? 25), $errno, $err, 3);
            if ($fp === false) {
                return ['status' => 'failed', 'details' => ['error' => $err]];
            }
            fclose($fp);
            return ['status' => 'healthy', 'details' => ['host' => ($parts['host'] ?? '') . ':' . ($parts['port'] ?? 25)]];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'details' => ['error' => $e->getMessage()]];
        }
    }

    private function dsn(): string
    {
        return trim((string) ($this->settings->get('notifications.smtp_dsn') ?? ''));
    }
}
