<?php

declare(strict_types=1);

use App\Api\V1\Middleware\RequireFeature;
use App\Modules\MultiDb\Admin\MultiDbController;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$ds = '{dataset:[a-z]{2,32}}';
$id = '{id:[0-9A-Za-z-]{1,64}}';

/** Multi-DB demo (flag module.multidb). "/create" is registered before "/{id}" so it is not taken for an id. */
return [
    Group::create('/multidb')
        ->middleware(RequireFeature::for('module.multidb'), RequirePermission::for('multidb.view'))
        ->routes(
            Route::get('')->action([MultiDbController::class, 'index'])->name('admin.multidb'),
            Route::post('/install')->middleware(RequirePermission::for('multidb.install'))->action([MultiDbController::class, 'install'])->name('admin.multidb.install'),
            Route::get('/' . $ds)->action([MultiDbController::class, 'list'])->name('admin.multidb.list'),
            Route::get('/' . $ds . '/create')->middleware(RequirePermission::for('multidb.create'))->action([MultiDbController::class, 'createForm'])->name('admin.multidb.create'),
            Route::post('/' . $ds . '/create')->middleware(RequirePermission::for('multidb.create'))->action([MultiDbController::class, 'store'])->name('admin.multidb.store'),
            Route::get('/' . $ds . '/' . $id)->action([MultiDbController::class, 'view'])->name('admin.multidb.view'),
            Route::get('/' . $ds . '/' . $id . '/edit')->middleware(RequirePermission::for('multidb.update'))->action([MultiDbController::class, 'editForm'])->name('admin.multidb.edit'),
            Route::post('/' . $ds . '/' . $id . '/edit')->middleware(RequirePermission::for('multidb.update'))->action([MultiDbController::class, 'update'])->name('admin.multidb.update'),
            Route::post('/' . $ds . '/' . $id . '/delete')->middleware(RequirePermission::for('multidb.delete'))->action([MultiDbController::class, 'delete'])->name('admin.multidb.delete'),
        ),
];
