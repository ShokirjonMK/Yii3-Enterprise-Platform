<?php

declare(strict_types=1);

use App\Platform\Appearance\ThemeRegistry;

/**
 * Design themes: built-in packs are read from themes/<code>/ (APP_THEMES_DIR overrides the directory).
 *
 * Author: ShokirjonMK
 */
return [
    ThemeRegistry::class => [
        '__construct()' => ['directory' => getenv('APP_THEMES_DIR') ?: dirname(__DIR__, 3) . '/themes'],
    ],
];
