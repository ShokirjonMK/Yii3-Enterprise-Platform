<?php

declare(strict_types=1);

use App\Api\V1\Middleware\RequireFeature;
use App\Modules\MultiDb\Web\MultiDbController;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$ds = '{dataset:[a-z]{2,32}}';
$id = '{id:[0-9A-Za-z-]{1,64}}';

/** Multi-DB demo pages for signed-in users (inside the authenticated web group). */
return [
    Group::create('/multidb')
        ->middleware(RequireFeature::for('module.multidb'), RequirePermission::for('multidb.view'))
        ->routes(
            Route::get('')->action([MultiDbController::class, 'index'])->name('web.multidb'),
            Route::get('/' . $ds)->action([MultiDbController::class, 'list'])->name('web.multidb.list'),
            Route::get('/' . $ds . '/new')->middleware(RequirePermission::for('multidb.create'))->action([MultiDbController::class, 'createForm'])->name('web.multidb.create'),
            Route::post('/' . $ds . '/new')->middleware(RequirePermission::for('multidb.create'))->action([MultiDbController::class, 'store'])->name('web.multidb.store'),
            Route::get('/' . $ds . '/' . $id)->action([MultiDbController::class, 'view'])->name('web.multidb.view'),
            Route::get('/' . $ds . '/' . $id . '/edit')->middleware(RequirePermission::for('multidb.update'))->action([MultiDbController::class, 'editForm'])->name('web.multidb.edit'),
            Route::post('/' . $ds . '/' . $id . '/edit')->middleware(RequirePermission::for('multidb.update'))->action([MultiDbController::class, 'update'])->name('web.multidb.update'),
            Route::post('/' . $ds . '/' . $id . '/delete')->middleware(RequirePermission::for('multidb.delete'))->action([MultiDbController::class, 'delete'])->name('web.multidb.delete'),
        ),
];
