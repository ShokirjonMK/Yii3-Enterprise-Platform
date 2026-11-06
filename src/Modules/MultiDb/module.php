<?php

declare(strict_types=1);

/**
 * Business module manifest (read by Admin → Business modules and docs).
 */
return [
    'code' => 'multidb',
    'name' => 'Multi-DB demo',
    'version' => '1.0.0',
    'flag' => 'module.multidb',
    'admin_route' => 'admin.multidb',
    'web_route' => 'web.multidb',
    'api_prefix' => '/api/v1/multidb',
    'permissions' => ['multidb.view', 'multidb.create', 'multidb.update', 'multidb.delete', 'multidb.install'],
    'jobs' => [],
    'topics' => [],
    'consumer_groups' => [],
    'description' => 'Reference for working with several databases: one CRUD (Admin, Web, REST API, console) over MariaDB, two PostgreSQL servers, MongoDB and ClickHouse.',
];
