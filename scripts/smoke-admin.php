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
    $r = $web->get((string) $path);
    $ok = $r->getStatusCode() === 200 && str_contains($web->body(), 'id="main"');
    $fail += $ok ? 0 : 1;
    $rows[] = sprintf('%-4s %-30s %-24s %s', $ok ? 'OK' : 'FAIL', sprintf('%02d %s', $item['n'], $item['label']), $path, $r->getStatusCode());
}
foreach (['/admin/projects' => 'Projects (module)', '/admin/contracts' => 'Contracts (Gii module)', '/admin/devtools/gii' => 'Enterprise Gii', '/projects' => 'Web: projects', '/account' => 'Web: profile', '/api/docs' => 'API docs'] as $path => $label) {
    $r = $web->get($path);
    $ok = $r->getStatusCode() === 200;
    $fail += $ok ? 0 : 1;
    $rows[] = sprintf('%-4s %-30s %-24s %s', $ok ? 'OK' : 'FAIL', $label, $path, $r->getStatusCode());
}
$r = $web->json('GET', '/api/v1/health');
$rows[] = sprintf('%-4s %-30s %-24s %s', $r->getStatusCode() === 200 ? 'OK' : 'FAIL', 'API v1 health', '/api/v1/health', $r->getStatusCode());
echo implode("\n", $rows), "\n";
echo $fail === 0 ? "ALL OK\n" : "FAILED: $fail\n";
Fixtures::cleanup();
exit($fail === 0 ? 0 : 1);
