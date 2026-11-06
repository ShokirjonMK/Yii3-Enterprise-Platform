<?php

declare(strict_types=1);

namespace App\Admin\Menu;

/**
 * Platform Control Center navigation — the 24 sections. Items are shown only when the user holds
 * the permission (the routes enforce the same permission server-side).
 */
final class AdminMenu
{
    /**
     * @return list<array{group:string, items:list<array{n:int, label:string, route:string, icon:string, permission:string}>}>
     */
    public static function groups(): array
    {
        return [
            ['group' => 'Overview', 'items' => [
                ['n' => 1, 'label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'dashboard', 'permission' => 'admin.access'],
            ]],
            ['group' => 'Identity & access', 'items' => [
                ['n' => 2, 'label' => 'Users & Organizations', 'route' => 'admin.users', 'icon' => 'users', 'permission' => 'users.view'],
                ['n' => 3, 'label' => 'Authentication', 'route' => 'admin.auth', 'icon' => 'key', 'permission' => 'auth.view'],
                ['n' => 4, 'label' => 'RBAC & Access Control', 'route' => 'admin.rbac', 'icon' => 'shield', 'permission' => 'rbac.view'],
                ['n' => 5, 'label' => 'Multi-Tenancy', 'route' => 'admin.tenants', 'icon' => 'building', 'permission' => 'tenants.view'],
            ]],
            ['group' => 'Integration', 'items' => [
                ['n' => 6, 'label' => 'API Management', 'route' => 'admin.api', 'icon' => 'api', 'permission' => 'api.view'],
                ['n' => 7, 'label' => 'Integrations', 'route' => 'admin.integrations', 'icon' => 'plug', 'permission' => 'integrations.view'],
                ['n' => 8, 'label' => 'Messaging & Notifications', 'route' => 'admin.notifications', 'icon' => 'bell', 'permission' => 'notifications.view'],
                ['n' => 9, 'label' => 'Workflow & Approvals', 'route' => 'admin.workflow', 'icon' => 'flow', 'permission' => 'workflow.view'],
            ]],
            ['group' => 'Runtime', 'items' => [
                ['n' => 10, 'label' => 'Jobs & Scheduler', 'route' => 'admin.jobs', 'icon' => 'clock', 'permission' => 'jobs.view'],
                ['n' => 11, 'label' => 'Kafka Management', 'route' => 'admin.kafka', 'icon' => 'stream', 'permission' => 'kafka.view'],
                ['n' => 12, 'label' => 'PostgreSQL Management', 'route' => 'admin.postgres', 'icon' => 'database', 'permission' => 'db.view'],
                ['n' => 25, 'label' => 'Databases', 'route' => 'admin.databases', 'icon' => 'database', 'permission' => 'databases.view'],
                ['n' => 13, 'label' => 'Redis Management', 'route' => 'admin.redis', 'icon' => 'bolt', 'permission' => 'redis.view'],
                ['n' => 14, 'label' => 'Storage & Search', 'route' => 'admin.storage', 'icon' => 'box', 'permission' => 'storage.view'],
            ]],
            ['group' => 'Operations', 'items' => [
                ['n' => 15, 'label' => 'Infrastructure', 'route' => 'admin.infrastructure', 'icon' => 'server', 'permission' => 'infra.view'],
                ['n' => 16, 'label' => 'CI/CD & Deployments', 'route' => 'admin.deployments', 'icon' => 'rocket', 'permission' => 'deploy.view'],
                ['n' => 17, 'label' => 'Monitoring & Observability', 'route' => 'admin.monitoring', 'icon' => 'activity', 'permission' => 'monitoring.view'],
                ['n' => 18, 'label' => 'Security Center', 'route' => 'admin.security', 'icon' => 'lock', 'permission' => 'security.view'],
                ['n' => 19, 'label' => 'Backup & Recovery', 'route' => 'admin.backups', 'icon' => 'archive', 'permission' => 'backup.view'],
                ['n' => 20, 'label' => 'Audit & Compliance', 'route' => 'admin.audit', 'icon' => 'file', 'permission' => 'audit.view'],
                ['n' => 24, 'label' => 'Incident Management', 'route' => 'admin.incidents', 'icon' => 'alert', 'permission' => 'incidents.view'],
            ]],
            ['group' => 'Platform', 'items' => [
                ['n' => 21, 'label' => 'System Settings', 'route' => 'admin.settings', 'icon' => 'settings', 'permission' => 'settings.view'],
                ['n' => 22, 'label' => 'Developer Tools', 'route' => 'admin.devtools', 'icon' => 'code', 'permission' => 'devtools.use'],
                ['n' => 23, 'label' => 'Business Modules', 'route' => 'admin.modules', 'icon' => 'grid', 'permission' => 'modules.view'],
            ]],
            ['group' => 'Business modules', 'items' => [
                ['n' => 0, 'label' => 'Projects', 'route' => 'admin.projects', 'icon' => 'folder', 'permission' => 'projects.view'],
                ['n' => 0, 'label' => 'Multi-DB demo', 'route' => 'admin.multidb', 'icon' => 'database', 'permission' => 'multidb.view'],
            ]],
        ];
    }

    /** @return list<array{n:int, label:string, route:string, icon:string, permission:string}> */
    public static function flat(): array
    {
        $items = [];
        foreach (self::groups() as $g) {
            foreach ($g['items'] as $i) {
                $items[] = $i;
            }
        }
        $items = array_values(array_filter($items, static fn($i) => $i['n'] > 0));
        usort($items, static fn($a, $b) => $a['n'] <=> $b['n']);
        return $items;
    }
}
