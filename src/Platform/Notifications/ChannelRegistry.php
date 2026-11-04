<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Platform\Queue\PermanentJobFailure;
use Psr\Container\ContainerInterface;

/**
 * Channel code → adapter class (config/common/notification-channels.php).
 */
final class ChannelRegistry
{
    /** @param array<string, class-string<NotificationChannelInterface>> $map */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $map,
    ) {}

    public function get(string $code): NotificationChannelInterface
    {
        $class = $this->map[$code] ?? throw new PermanentJobFailure("No adapter for notification channel \"$code\".");
        return $this->container->get($class);
    }

    /** @return array<string, class-string<NotificationChannelInterface>> */
    public function all(): array
    {
        return $this->map;
    }
}
