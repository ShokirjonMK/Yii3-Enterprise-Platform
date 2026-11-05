<?php

declare(strict_types=1);

use App\Console\Command;

/** Design themes (docs/THEMES.md). Author: ShokirjonMK */
return [
    'theme:list' => Command\ThemeListCommand::class,
    'theme:install' => Command\ThemeInstallCommand::class,
    'theme:activate' => Command\ThemeActivateCommand::class,
    'theme:make' => Command\ThemeMakeCommand::class,
];
