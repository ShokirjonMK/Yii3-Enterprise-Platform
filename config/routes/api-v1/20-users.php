<?php

declare(strict_types=1);

use App\Api\V1\Action\Users\UsersController;
use App\Api\V1\Middleware\BearerAuthentication;
use App\Api\V1\Middleware\RequireApiPermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$id = '{id:[0-9a-fA-F-]{36}}';

return [
    Group::create('/users')
        ->middleware(BearerAuthentication::class)
        ->routes(
            Route::get('')->middleware(RequireApiPermission::for('users.view', 'users.read'))->action([UsersController::class, 'index'])->name('api.v1.users'),
            Route::post('')->middleware(RequireApiPermission::for('users.manage', 'users.write'))->action([UsersController::class, 'create'])->name('api.v1.users.create'),
            Route::get('/' . $id)->middleware(RequireApiPermission::for('users.view', 'users.read'))->action([UsersController::class, 'view'])->name('api.v1.users.view'),
            Route::patch('/' . $id)->middleware(RequireApiPermission::for('users.manage', 'users.write'))->action([UsersController::class, 'update'])->name('api.v1.users.update'),
            Route::post('/' . $id . '/block')->middleware(RequireApiPermission::for('users.manage', 'users.write'))->action([UsersController::class, 'block'])->name('api.v1.users.block'),
            Route::post('/' . $id . '/unblock')->middleware(RequireApiPermission::for('users.manage', 'users.write'))->action([UsersController::class, 'unblock'])->name('api.v1.users.unblock'),
        ),
];
