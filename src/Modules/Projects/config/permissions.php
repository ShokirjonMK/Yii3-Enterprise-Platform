<?php

declare(strict_types=1);

/**
 * Projects module RBAC catalog (merged with platform.php by `./yii rbac:sync`).
 * Existing roles are extended (permissions are merged, descriptions kept).
 */
return [
    'permissions' => [
        'projects.view' => 'View projects of own tenant',
        'projects.create' => 'Create projects',
        'projects.update' => 'Edit own projects and change their status',
        'projects.delete' => 'Archive projects',
        'projects.approve' => 'Approve (activate) draft projects',
        'projects.manage_all' => 'Manage projects owned by other users',
    ],
    'roles' => [
        'business_admin' => [
            'permissions' => [
                'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.approve', 'projects.manage_all',
            ],
        ],
        'user' => ['permissions' => ['projects.view']],
        'developer' => ['permissions' => ['projects.view']],
    ],
];
