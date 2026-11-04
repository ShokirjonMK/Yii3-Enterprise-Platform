<?php

declare(strict_types=1);

namespace App\Platform\Gii;

use App\Platform\Gii\Spec\GiiSpecException;

/**
 * Path safety for generated files: a target must be a relative path without traversal, inside one of the
 * module-owned directories, resolve inside the (real) project root and must not be a symlink.
 */
final class PathGuard
{
    /** Only these locations may receive generated code. */
    public const ALLOWED_PREFIXES = [
        'src/Modules/', 'src/Admin/Action/', 'src/Web/Action/', 'src/Api/V1/Action/',
        'resources/views/admin/', 'resources/views/web/',
        'config/routes/admin/', 'config/routes/web/', 'config/routes/api-v1/',
        'config/common/permissions/', 'config/common/jobs/', 'config/common/events/', 'config/common/di/',
        'migrations/', 'tests/Unit/', 'tests/Integration/', 'tests/Api/', 'tests/E2E/',
    ];

    /** Platform code that generators must never touch, even inside allowed prefixes. */
    private const FORBIDDEN = ['src/Admin/Action/DevTools/', 'src/Modules/Projects/', 'src/Api/V1/Action/Auth/', 'src/Api/V1/Action/Docs/'];

    /** @return string absolute path */
    public static function resolve(string $root, string $relative): string
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new GiiSpecException(['root' => ["Generator root \"$root\" does not exist."]]);
        }
        $rel = str_replace('\\', '/', $relative);
        if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('~(^|/)\.\.?(/|$)~', $rel) === 1
            || preg_match('~^[A-Za-z]:~', $rel) === 1 || preg_match('~[^A-Za-z0-9_./-]~', $rel) === 1) {
            throw new GiiSpecException(['path' => ["Unsafe path \"$relative\"."]]);
        }
        $allowed = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                $allowed = true;
                break;
            }
        }
        foreach (self::FORBIDDEN as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                $allowed = false;
            }
        }
        if (!$allowed || !str_ends_with($rel, '.php')) {
            throw new GiiSpecException(['path' => ["Path \"$relative\" is outside the locations Enterprise Gii may write to."]]);
        }
        $abs = $realRoot . '/' . $rel;
        if (is_link($abs)) {
            throw new GiiSpecException(['path' => ["Refusing to write through the symlink \"$relative\"."]]);
        }
        // the deepest existing ancestor must resolve inside the root (no symlinked directories leading out)
        $dir = dirname($abs);
        while (!is_dir($dir) && $dir !== dirname($dir)) {
            $dir = dirname($dir);
        }
        $realDir = realpath($dir);
        if ($realDir === false || ($realDir !== $realRoot && !str_starts_with($realDir . '/', $realRoot . '/'))) {
            throw new GiiSpecException(['path' => ["Path \"$relative\" resolves outside the project root."]]);
        }
        return $abs;
    }
}
