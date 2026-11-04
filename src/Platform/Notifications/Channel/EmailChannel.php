<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Channel;

use App\Platform\Notifications\NotificationChannelInterface;
use App\Platform\Queue\PermanentJobFailure;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * SMTP e-mail via symfony/mailer (transport from MAILER_DSN). Plain-text body; HTML is not generated
 * from user-controlled template variables to avoid injection.
 */
final class EmailChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $from,
        private readonly string $dsn,
    ) {}

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
        $email = (new Email())
            ->from(Address::create($this->from))
            ->to($to)
            ->subject((string) $notification['subject'])
            ->text((string) $notification['body']);
        $email->getHeaders()->addTextHeader('X-Notification-Id', (string) $notification['id']);
        $sent = $this->transport->send($email);
        return $sent?->getMessageId();
    }

    public function health(): array
    {
        if ($this->dsn === '' || str_starts_with($this->dsn, 'null://')) {
            return ['status' => 'unconfigured', 'details' => []];
        }
        try {
            $parts = parse_url($this->dsn);
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
}
