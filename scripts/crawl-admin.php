<?php

declare(strict_types=1);

/**
 * Link crawler for the running stack: signs in as a temporary superadmin, follows every same-origin
 * <a href> (GET only — forms and buttons are never submitted) starting at /admin, /, /account and reports
 * every page that does not answer 200 (404, 500, …) together with the page that links to it.
 *
 *   docker compose exec -T app php scripts/crawl-admin.php [--max=1500]
 *
 * Author: ShokirjonMK
 */

require dirname(__DIR__) . '/vendor/autoload.php';
App\Environment::prepare();

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;

$max = 1500;
foreach ($argv as $a) {
    if (str_starts_with($a, '--max=')) {
        $max = (int) substr($a, 6);
    }
}

Fixtures::resetLoginThrottle();
[$web] = AdminSession::login(['superadmin']);

// never follow links that change state through GET or end the session
$skip = '#^/(logout|auth/logout|api/v1/|metrics|static/|assets/|health|.*\.(?:csv|json|sh|zip|gz|sql)$|.*/download|.*/export)#';
$queue = ['/admin' => '(start)', '/' => '(start)', '/account' => '(start)', '/projects' => '(start)', '/api/docs' => '(start)'];
$seen = [];
$bad = [];
$slow = [];
$n = 0;
while ($queue !== [] && $n < $max) {
    $path = array_key_first($queue);
    $from = $queue[$path];
    unset($queue[$path]);
    if (isset($seen[$path])) {
        continue;
    }
    $seen[$path] = true;
    $n++;
    $t = microtime(true);
    $r = $web->get($path);
    $ms = (int) round((microtime(true) - $t) * 1000);
    $code = $r->getStatusCode();
    if ($code >= 300 && $code < 400) {
        $loc = (string) parse_url($r->getHeaderLine('Location'), PHP_URL_PATH);
        if ($loc !== '' && !isset($seen[$loc])) {
            $queue[$loc] ??= $path;
        }
        continue;
    }
    if ($code !== 200) {
        $bad[] = sprintf('%d  %-60s  ← %s', $code, $path, $from);
        continue;
    }
    if ($ms > 1000) {
        $slow[] = sprintf('%5d ms  %s', $ms, $path);
    }
    if (!str_contains($r->getHeaderLine('Content-Type'), 'text/html')) {
        continue;
    }
    preg_match_all('/<a\s[^>]*href="([^"#]+)"/i', $web->body(), $m);
    foreach ($m[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES);
        if (preg_match('#^(https?:)?//#', $href) || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
            continue;
        }
        $p = (string) parse_url($href, PHP_URL_PATH);
        $q = (string) parse_url($href, PHP_URL_QUERY);
        if ($p === '' || preg_match($skip, $p)) {
            continue;
        }
        // keep one variant per path + query keys (filters/sorting/pages are not crawled exhaustively)
        parse_str($q, $qa);
        unset($qa['page'], $qa['sort']);
        $url = $p . ($qa === [] ? '' : '?' . http_build_query($qa));
        $norm = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\b\d+\b/', '{id}', $p) . '?' . implode(',', array_keys($qa));
        if (isset($seen['norm:' . $norm])) {
            continue;
        }
        $seen['norm:' . $norm] = true;
        if (!isset($seen[$url])) {
            $queue[$url] ??= $path;
        }
    }
}
Fixtures::cleanup();
printf("crawled %d page(s); %d not OK; %d slower than 1 s\n", $n, count($bad), count($slow));
foreach ($bad as $b) {
    echo "  $b\n";
}
foreach ($slow as $s) {
    echo "  SLOW $s\n";
}
exit($bad === [] ? 0 : 1);
