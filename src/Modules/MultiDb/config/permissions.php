<?php

declare(strict_types=1);

/**
 * Multi-DB demo RBAC catalog (merged with platform.php by `./yii rbac:sync`).
 */
return [
    'permissions' => [
        'multidb.view' => 'View the multi-database demo datasets',
        'multidb.create' => 'Create rows in the multi-database demo datasets',
        'multidb.update' => 'Edit rows in the multi-database demo datasets',
        'multidb.delete' => 'Delete rows in the multi-database demo datasets',
        'multidb.install' => 'Create the demo tables and load sample data in the extra databases',
    ],
    'roles' => [
        'business_admin' => [
            'permissions' => ['multidb.view', 'multidb.create', 'multidb.update', 'multidb.delete', 'multidb.install'],
        ],
        'developer' => ['permissions' => ['multidb.view', 'multidb.create', 'multidb.update', 'multidb.delete']],
        'user' => ['permissions' => ['multidb.view']],
    ],
];
