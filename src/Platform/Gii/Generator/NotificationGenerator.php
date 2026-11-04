<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Notification: template seed migration for notification_templates (uz/ru/en; in-app, e-mail, Telegram)
 * and the Notifier call site used by the service (status change, or creation when there is no lifecycle).
 */
final class NotificationGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'notification';
    }

    public static function getName(): string
    {
        return 'Notification';
    }

    public static function getDescription(): string
    {
        return 'Notification template seed (uz/ru/en) and the Notifier call site used by the service.';
    }

    public static function requires(): array
    {
        return ['entity'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Application/' . $m->entity . 'Notifier.php');
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->modulePath('Application/' . $m->entity . 'Notifier.php') => 'notifier.php',
            $m->migrationPath('Notifications') => 'migration.php',
        ];
    }
}
