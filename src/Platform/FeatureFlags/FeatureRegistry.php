<?php

declare(strict_types=1);

namespace App\Platform\FeatureFlags;

use App\Platform\FeatureFlags\RequireFeature;
use Yiisoft\Router\Group;

/**
 * Reads config/common/features.php (optional features) and helps route loaders / menus apply it.
 *
 * Author: ShokirjonMK
 */
final class FeatureRegistry
{
    private static ?array $features = null;

    /** @return array<string, array<string,mixed>> */
    public static function all(): array
    {
        return self::$features ??= require dirname(__DIR__, 3) . '/config/common/features.php';
    }

    /** Flag that gates the given route file of a kind (admin|web|api|system), or null. */
    public static function flagForFile(string $kind, string $file): ?string
    {
        $path = str_replace('\\', '/', $file);
        // Business module area: src/Modules/<Name>/{Admin|Web|Api}/routes.php → module.<code>
        if (preg_match('~/src/Modules/([A-Za-z0-9]+)/(?:Admin|Web|Api)/routes\.php$~', $path, $m)) {
            return 'module.' . self::moduleCode($m[1], dirname($path, 2));
        }
        // Section folder (src/Admin/<Section>/routes.php → "<Section>") or legacy route file name
        $base = basename($path) === 'routes.php' ? basename(dirname($path)) : basename($path);
        foreach (self::all() as $flag => $def) {
            if (in_array($base, (array) ($def[$kind] ?? []), true)) {
                return $flag;
            }
        }
        // Module route files: web/<code>.php, admin/NNx-<code>.php, api-v1/NN-<code>.php → module.<code> when it exists
        if (preg_match('/^(?:\d+[a-z]?-)?([a-z][a-z0-9_]*)\.php$/', $base, $m) && is_dir(dirname(__DIR__, 2) . '/Modules/' . ucfirst($m[1]))) {
            return 'module.' . $m[1];
        }
        return null;
    }

    /** Module code from its manifest (module.php 'code'), default = lower-case folder name. */
    private static function moduleCode(string $dir, string $moduleDir): string
    {
        static $codes = [];
        if (!isset($codes[$moduleDir])) {
            $manifest = is_file($moduleDir . '/module.php') ? (require $moduleDir . '/module.php') : [];
            $codes[$moduleDir] = is_array($manifest) && is_string($manifest['code'] ?? null) ? $manifest['code'] : strtolower($dir);
        }
        return $codes[$moduleDir];
    }

    /** Flag that hides the given admin menu route, or null (core section). */
    public static function flagForMenu(string $route): ?string
    {
        foreach (self::all() as $flag => $def) {
            if (in_array($route, (array) ($def['menu'] ?? []), true)) {
                return $flag;
            }
        }
        if (preg_match('/^admin\.([a-z][a-z0-9_]*)$/', $route, $m) && is_dir(dirname(__DIR__, 2) . '/Modules/' . ucfirst($m[1]))) {
            return 'module.' . $m[1];
        }
        return null;
    }

    /**
     * Loads route files of a kind, wrapping each gated file in a RequireFeature group.
     *
     * @param list<string> $files
     * @return list<object>
     */
    public static function loadRoutes(string $kind, array $files): array
    {
        $routes = [];
        sort($files);
        foreach ($files as $file) {
            $defined = require $file;
            $flag = self::flagForFile($kind, $file);
            if ($flag === null) {
                $routes = [...$routes, ...$defined];
            } else {
                $routes[] = Group::create()->middleware(RequireFeature::for($flag, explain: $kind === 'admin'))->routes(...$defined);
            }
        }
        return $routes;
    }
}
