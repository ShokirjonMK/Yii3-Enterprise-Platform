<?php

declare(strict_types=1);

use App\Console\Command;

/**
 * Console commands. Modules may add config/console/commands/*.php returning [name => class].
 */
$commands = [
    'platform:install' => Command\InstallCommand::class,
    'rbac:sync' => Command\RbacSyncCommand::class,
    'platform:seed' => Command\SeedCommand::class,
    'platform:demo' => Command\DemoCommand::class,
    'queue:work' => Command\QueueWorkCommand::class,
    'worker:health' => Command\WorkerHealthCommand::class,
    'scheduler:run' => Command\SchedulerRunCommand::class,
    'outbox:relay' => Command\OutboxRelayCommand::class,
    'kafka:consume' => Command\KafkaConsumeCommand::class,
    'kafka:provision' => Command\KafkaProvisionCommand::class,
    'kafka:publish' => Command\KafkaPublishCommand::class,
    'kafka:handlers' => Command\KafkaHandlersCommand::class,
    'audit:verify' => Command\AuditVerifyCommand::class,
];

foreach ([...(glob(__DIR__ . '/commands/*.php') ?: []), ...(glob(dirname(__DIR__, 2) . '/src/Modules/*/config/commands.php') ?: [])] as $file) {
    $commands = array_merge($commands, require $file);
}

return $commands;
