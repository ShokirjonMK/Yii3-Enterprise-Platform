<?php

declare(strict_types=1);

/**
 * Business modules: DI definitions from src/Modules/<Name>/config/di.php (one file per module, `$params` available).
 * Other module config is discovered the same way: config/{jobs,events}.php, RbacCatalog (permissions.php),
 * config/console/commands.php (commands.php), params.php (migrations/), config/routes/*.php (Admin|Web|Api/routes.php).
 *
 * Author: ShokirjonMK
 */
$definitions = [];
foreach (glob(dirname(__DIR__, 3) . '/src/Modules/*/config/di.php') ?: [] as $moduleDi) {
    $definitions = array_merge($definitions, require $moduleDi);
}

return $definitions;
