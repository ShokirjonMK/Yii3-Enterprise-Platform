<?php

declare(strict_types=1);

/**
 * Live smoke check: logs in as a temporary superadmin (password + TOTP) and opens every
 * Platform Control Center section, the web app and the API against the running stack.
 *
 *   docker compose exec -T app php scripts/smoke-admin.php
 *
 * Author: ShokirjonMK
 */

require dirname(__DIR__) . '/vendor/autoload.php';
App\Environment::prepare();

use App\Admin\Menu\AdminMenu;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;

Fixtures::resetLoginThrottle();
[$web] = AdminSession::login(['superadmin']);
$fail = 0;
$rows = [];
$urls = [];
foreach (AdminMenu::flat() as $item) {
    $urls[sprintf('%02d %s', $item['n'], $item['label'])] = null;
}
// resolve paths by convention from route names
$paths = [
    1 => '/admin', 2 => '/admin/users', 3 => '/admin/auth', 4 => '/admin/rbac', 5 => '/admin/tenants', 6 => '/admin/api',
    7 => '/admin/integrations', 8 => '/admin/notifications', 9 => '/admin/workflow', 10 => '/admin/jobs', 11 => '/admin/kafka',
    12 => '/admin/postgres', 13 => '/admin/redis', 14 => '/admin/storage', 15 => '/admin/infrastructure', 16 => '/admin/deployments',
    17 => '/admin/monitoring', 18 => '/admin/security', 19 => '/admin/backups', 20 => '/admin/audit', 21 => '/admin/settings',
    22 => '/admin/devtools', 23 => '/admin/modules', 24 => '/admin/incidents',
];
foreach (AdminMenu::flat() as $item) {
    $path = $paths[$item['n']] ?? null;
    $t = microtime(true);
    $r = $web->get((string) $path);
    $ms = (int) round((microtime(true) - $t) * 1000);
    $ok = $r->getStatusCode() === 200 && str_contains($web->body(), 'id="main"');
    // 404 is expected for (a) a section whose optional feature flag is OFF and (b) Developer Tools in prod (fast) mode
    $flag = App\Platform\FeatureFlags\FeatureRegistry::flagForMenu($item['route']);
    $flagOff = $flag !== null && !Fixtures::db()->createCommand('SELECT enabled FROM feature_flags WHERE code = :c', [':c' => $flag])->queryScalar();
    $na = !$ok && $r->getStatusCode() === 404 && ($flagOff || str_starts_with((string) $path, '/admin/devtools'));
    $fail += ($ok || $na) ? 0 : 1;
    $rows[] = sprintf('%-4s %-30s %-24s %s %5d ms%s', $ok ? 'OK' : ($na ? ($flagOff ? 'OFF' : 'N/A') : 'FAIL'), sprintf('%02d %s', $item['n'], $item['label']), $path, $r->getStatusCode(), $ms, $flagOff ? "  (flag $flag disabled)" : '');
}
foreach (['/admin/projects' => 'Projects (module)', '/admin/contracts' => 'Contracts (Gii module)', '/admin/devtools/gii' => 'Enterprise Gii', '/projects' => 'Web: projects', '/account' => 'Web: profile', '/api/docs' => 'API docs'] as $path => $label) {
    $t = microtime(true);
    $r = $web->get($path);
    $ms = (int) round((microtime(true) - $t) * 1000);
    $ok = $r->getStatusCode() === 200;
    $na = !$ok && $r->getStatusCode() === 404 && str_starts_with($path, '/admin/devtools');
    $fail += ($ok || $na) ? 0 : 1;
    $rows[] = sprintf('%-4s %-30s %-24s %s %5d ms', $ok ? 'OK' : ($na ? 'N/A' : 'FAIL'), $label, $path, $r->getStatusCode(), $ms);
}
$r = $web->json('GET', '/api/v1/health');
$rows[] = sprintf('%-4s %-30s %-24s %s', $r->getStatusCode() === 200 ? 'OK' : 'FAIL', 'API v1 health', '/api/v1/health', $r->getStatusCode());
echo implode("\n", $rows), "\n";
echo $fail === 0 ? "ALL OK\n" : "FAILED: $fail\n";
Fixtures::cleanup();
exit($fail === 0 ? 0 : 1);
