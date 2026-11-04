<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Workflow: definition migration for the platform WorkflowEngine (states and forward transitions from the
 * status enum) and the bridge class that starts and mirrors workflow instances.
 */
final class WorkflowGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'workflow';
    }

    public static function getName(): string
    {
        return 'Workflow';
    }

    public static function getDescription(): string
    {
        return 'WorkflowEngine definition migration (states/transitions from the status enum) and the bridge that starts and mirrors instances.';
    }

    public static function requires(): array
    {
        return ['entity'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return $m->modulePath('Application/' . $m->entity . 'Workflow.php');
    }

    public static function assertApplicable(ModuleSpec $m): void
    {
        if (!$m->hasStatus()) {
            self::notApplicable('The workflow generator needs a lifecycle field: status:enum(a|b|...).');
        }
    }

    protected function files(ModuleSpec $m): array
    {
        return [
            $m->migrationPath('Workflow') => 'migration.php',
            $m->modulePath('Application/' . $m->entity . 'Workflow.php') => 'bridge.php',
        ];
    }
}
