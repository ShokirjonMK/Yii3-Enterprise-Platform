<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Scheduler: seed migration for `schedules` running the module cleanup job nightly through the platform
 * Scheduler (leader-elected, exactly-once per tick).
 */
final class SchedulerGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'scheduler';
    }

    public static function getName(): string
    {
        return 'Scheduler';
    }

    public static function getDescription(): string
    {
        return 'Schedule seed migration (table schedules) running the cleanup job nightly.';
    }

    public static function requires(): array
    {
        return ['job'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->migrationPath('Schedule');
    }

    protected function files(ModuleSpec $m): array
    {
        return [$m->migrationPath('Schedule') => 'migration.php'];
    }
}
