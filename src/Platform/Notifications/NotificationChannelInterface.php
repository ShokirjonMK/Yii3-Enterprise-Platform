<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

/**
 * Delivery adapter for one channel (email, telegram, sms, webpush, inapp).
 */
interface NotificationChannelInterface
{
    public function code(): string;

    /**
     * Delivers a notification row. Throw to trigger a retry (job backoff);
     * throw \App\Platform\Queue\PermanentJobFailure for non-retryable errors (invalid recipient).
     *
     * @param array<string,mixed> $notification row from `notifications`
     * @return string|null provider message id
     */
    public function send(array $notification): ?string;

    /** @return array{status:string, details:array<string,mixed>} healthy|degraded|failed|unconfigured */
    public function health(): array;
}
