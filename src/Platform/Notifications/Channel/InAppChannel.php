<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Channel;

use App\Platform\Notifications\NotificationChannelInterface;

/** In-app notifications are stored at creation time (Notifier); delivery is a no-op. */
final class InAppChannel implements NotificationChannelInterface
{
    public function code(): string
    {
        return 'inapp';
    }

    public function send(array $notification): ?string
    {
        return null;
    }

    public function health(): array
    {
        return ['status' => 'healthy', 'details' => []];
    }
}
