<?php

declare(strict_types=1);

use Yiisoft\Access\AccessCheckerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Rbac\AssignmentsStorageInterface;
use Yiisoft\Rbac\Db\AssignmentsStorage;
use Yiisoft\Rbac\Db\ItemsStorage;
use Yiisoft\Rbac\Db\TransactionalManagerDecorator;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\Manager;
use Yiisoft\Rbac\ManagerInterface;

/**
 * RBAC: official yiisoft/rbac Manager with yiisoft/rbac-db storages (PostgreSQL).
 * Default-deny: a permission that does not exist or is not assigned is always refused.
 */
return [
    ItemsStorageInterface::class => static fn(ConnectionInterface $db): ItemsStorageInterface => new ItemsStorage($db),
    AssignmentsStorageInterface::class => static fn(ConnectionInterface $db): AssignmentsStorageInterface => new AssignmentsStorage($db),

    ManagerInterface::class => static fn(
        ItemsStorageInterface $items,
        AssignmentsStorageInterface $assignments,
        ConnectionInterface $db,
    ): ManagerInterface => new TransactionalManagerDecorator(
        new Manager($items, $assignments, enableDirectPermissions: false, includeRolesInAccessChecks: false),
        $db,
    ),

    AccessCheckerInterface::class => ManagerInterface::class,
];
