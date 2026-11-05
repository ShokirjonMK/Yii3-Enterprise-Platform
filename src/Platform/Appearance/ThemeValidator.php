<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use App\Shared\Domain\Exception\ValidationException;
use ZipArchive;

/**
 * Validates theme packs (theme.json + theme.css) before they are installed.
 *
 * Zip: at most 512 KB, only theme.json and theme.css (optionally inside one top-level folder), no other entries,
 * no path traversal. Manifest: code [a-z0-9-]{2,40}, name, semver-like version, hex colours.
 * CSS (max 200 KB) must be self-contained: no @import, no url() to another host or scheme (only data: images
 * and relative paths), no expression()/behavior/-moz-binding/javascript:, no "<" (cannot break out of a style
 * context). CSS escapes are decoded before checking so "\6A avascript:" does not slip through.
 *
 * Author: ShokirjonMK
 */
final class ThemeValidator
{
    public const MAX_ZIP = 524288;
    public const MAX_CSS = 204800;
    public const MAX_JSON = 16384;
    public const CODE = '/^[a-z0-9][a-z0-9-]{1,39}$/';

    /** @return array{code:string,name:string,version:string,author:string,description:string,dark:bool,colors:array<string,string>} */
    public function manifest(array $m): array
    {
        $errors = [];
        $code = (string) ($m['code'] ?? '');
        if (preg_match(self::CODE, $code) !== 1) {
            $errors['code'][] = 'Theme code must be 2–40 characters: lowercase letters, digits and "-".';
        }
        $name = trim((string) ($m['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            $errors['name'][] = 'Theme name is required (max 60 characters).';
        }
        $version = (string) ($m['version'] ?? '1.0.0');
        if (preg_match('/^\d{1,4}(\.\d{1,4}){0,2}([-+][0-9A-Za-z.-]{1,20})?$/', $version) !== 1) {
            $errors['version'][] = 'Version must look like 1.0.0.';
        }
        $colors = [];
        foreach ((array) ($m['colors'] ?? []) as $k => $v) {
            if (!is_string($k) || preg_match('/^[a-z_]{1,24}$/', $k) !== 1 || !is_string($v) || preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) !== 1) {
                $errors['colors'][] = 'Colours must be a map of name → hex colour (#rrggbb).';
                break;
            }
            $colors[$k] = strtolower($v);
        }
        if (count($colors) > 12) {
            $errors['colors'][] = 'At most 12 preview colours.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'code' => $code,
            'name' => $name,
            'version' => $version,
            'author' => mb_substr(trim((string) ($m['author'] ?? '')), 0, 80),
            'description' => mb_substr(trim((string) ($m['description'] ?? '')), 0, 300),
            'dark' => (bool) ($m['dark'] ?? true),
            'colors' => $colors,
        ];
    }

    public function css(string $css): string
    {
        if (strlen($css) > self::MAX_CSS) {
            throw ValidationException::single('css', 'theme.css is larger than 200 KB.');
        }
        if (!mb_check_encoding($css, 'UTF-8')) {
            throw ValidationException::single('css', 'theme.css must be UTF-8.');
        }
        $css = str_replace("\0", '', $css);
        // decode CSS escapes (\6a, \000061, \j) and drop comments before scanning
        $decoded = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static fn(array $m) => mb_chr((int) hexdec($m[1])) ?: '', $css) ?? '';
        $decoded = preg_replace('/\\\\(.)/su', '$1', $decoded) ?? '';
        $scan = strtolower(preg_replace('#/\*.*?\*/#s', '', $decoded) ?? '');
        $scanCompact = preg_replace('/\s+/', '', $scan) ?? '';

        $rules = [
            '<' => 'HTML tags or "<" are not allowed in theme CSS.',
            '@import' => '@import is not allowed: a theme must be a single self-contained file.',
            'expression(' => 'expression() is not allowed.',
            'javascript:' => 'javascript: URLs are not allowed.',
            'vbscript:' => 'vbscript: URLs are not allowed.',
            '-moz-binding' => '-moz-binding is not allowed.',
            'behavior:' => 'behavior is not allowed.',
            '@namespace' => '@namespace is not allowed.',
        ];
        foreach ($rules as $needle => $message) {
            if (str_contains($scanCompact, str_replace(' ', '', $needle))) {
                throw ValidationException::single('css', $message);
            }
        }
        if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/s', $scan, $mm)) {
            foreach ($mm[2] as $url) {
                $url = trim($url);
                if (preg_match('#^data:image/(png|jpeg|gif|webp|svg\+xml)[;,]#', $url) === 1) {
                    continue;
                }
                if ($url === '' || str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#', $url) === 1 || str_contains($url, '..')) {
                    throw ValidationException::single('css', 'url() may reference only data: images or relative files of the platform, not "' . mb_substr($url, 0, 60) . '".');
                }
            }
        }
        if (substr_count($css, '{') !== substr_count($css, '}')) {
            throw ValidationException::single('css', 'Unbalanced braces in theme.css.');
        }
        return $css;
    }

    /**
     * @return array{0: array, 1: string} [validated manifest, sanitized css]
     */
    public function zip(string $path): array
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw ValidationException::single('file', 'Choose a theme .zip file.');
        }
        if (filesize($path) > self::MAX_ZIP) {
            throw ValidationException::single('file', 'The theme archive is larger than 512 KB.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ValidationException::single('file', 'The file is not a valid .zip archive.');
        }
        try {
            $json = $css = null;
            $prefix = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                if (str_ends_with($name, '/') && substr_count($name, '/') === 1 && !str_contains($name, '..')) {
                    continue; // a single top-level folder
                }
                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    throw ValidationException::single('file', 'The archive contains an unsafe path.');
                }
                $dir = str_contains($name, '/') ? substr($name, 0, (int) strrpos($name, '/') + 1) : '';
                $base = basename($name);
                if (!in_array($base, ['theme.json', 'theme.css'], true) || substr_count($dir, '/') > 1 || ($prefix !== null && $prefix !== $dir)) {
                    throw ValidationException::single('file', 'A theme archive may contain only theme.json and theme.css (optionally in one folder): found "' . mb_substr($name, 0, 80) . '".');
                }
                $prefix = $dir;
                $size = (int) ($stat['size'] ?? 0);
                if ($size > ($base === 'theme.css' ? self::MAX_CSS : self::MAX_JSON)) {
                    throw ValidationException::single('file', "$base is too large.");
                }
                $content = $zip->getFromIndex($i);
                if (!is_string($content)) {
                    throw ValidationException::single('file', "Cannot read $base.");
                }
                $base === 'theme.json' ? $json = $content : $css = $content;
            }
        } finally {
            $zip->close();
        }
        if ($json === null || $css === null) {
            throw ValidationException::single('file', 'The archive must contain theme.json and theme.css.');
        }
        $manifest = json_decode($json, true);
        if (!is_array($manifest)) {
            throw ValidationException::single('file', 'theme.json is not valid JSON.');
        }
        return [$this->manifest($manifest), $this->css($css)];
    }
}
