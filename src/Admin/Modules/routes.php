<?php

declare(strict_types=1);

use App\Admin\Modules\ModulesController as M;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$manage = RequirePermission::for('modules.manage');

return [
    Group::create('/modules')->middleware(RequirePermission::for('modules.view'))->routes(
        Route::get('')->action([M::class, 'index'])->name('admin.modules'),
        Route::post('/sync')->middleware($manage)->action([M::class, 'sync'])->name('admin.modules.sync'),
        Route::get('/{code:[a-z][a-z0-9_-]{1,63}}')->action([M::class, 'show'])->name('admin.modules.show'),
        Route::post('/{code:[a-z][a-z0-9_-]{1,63}}/toggle')->middleware($manage)->action([M::class, 'toggle'])->name('admin.modules.toggle'),
    ),
];
