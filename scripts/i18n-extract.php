<?php

declare(strict_types=1);

/**
 * Extracts translatable source strings ($h->t('…'), $h->tr('…'), admin menu labels) from views and src
 * and reports which are missing in resources/messages/{uz,ru}/app.php.
 *
 *   php scripts/i18n-extract.php            # summary
 *   php scripts/i18n-extract.php --missing  # JSON list of missing keys (for translators)
 *
 * Author: ShokirjonMK
 */

$root = dirname(__DIR__);
$strings = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/resources/views'));
$files = [];
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        $files[] = $f->getPathname();
    }
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')); // Gii templates are code generators, not UI
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        $files[] = $f->getPathname();
    }
}
foreach ($files as $file) {
    if (str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $file), '/Gii/templates/')) {
        continue;
    }
    $src = (string) file_get_contents($file);
    if (preg_match_all("/\\\$h->tr?\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m)) {
        foreach ($m[1] as $s) {
            $strings[stripslashes($s)] = true;
        }
    }
    if (str_ends_with($file, 'AdminMenu.php') && preg_match_all("/'(?:label|group)' => '([^']+)'/", $src, $m)) {
        foreach ($m[1] as $s) {
            $strings[$s] = true;
        }
    }
}
$keys = array_keys($strings);
sort($keys);
$missing = [];
foreach (['uz', 'ru'] as $lang) {
    $file = "$root/resources/messages/$lang/app.php";
    $have = is_file($file) ? require $file : [];
    $missing[$lang] = array_values(array_filter($keys, static fn($k) => !isset($have[$k])));
}
if (in_array('--missing', $argv, true)) {
    echo json_encode(['all' => $keys, 'missing' => $missing], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}
printf("strings: %d, missing uz: %d, missing ru: %d\n", count($keys), count($missing['uz']), count($missing['ru']));
exit($missing['uz'] === [] && $missing['ru'] === [] ? 0 : 1);
