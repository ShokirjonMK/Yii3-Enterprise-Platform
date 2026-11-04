<?php

declare(strict_types=1);

/**
 * Business module manifest (read by the Business Modules admin section and docs).
 */
return [
    'code' => 'projects',
    'name' => 'Projects',
    'version' => '1.0.0',
    'flag' => 'module.projects',
    'admin_route' => 'admin.projects',
    'web_route' => 'web.projects',
    'api_prefix' => '/api/v1/projects',
    'permissions' => ['projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.approve', 'projects.manage_all'],
    'jobs' => ['projects.report.generate'],
    'topics' => ['projects.events'],
    'consumer_groups' => ['projects-audit'],
    'description' => 'Reference business module: CRUD with lifecycle, RBAC, tenant isolation, audit, outbox events, consumer read model, background reports, Admin/Web UI and REST API.',
];
