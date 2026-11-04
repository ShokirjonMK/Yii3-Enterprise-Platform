<?php

declare(strict_types=1);

use App\Admin\Action\Settings\FeatureFlagsController as F;
use App\Admin\Action\Settings\SettingsController as S;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$manage = RequirePermission::for('settings.manage');
$key = '{key:[a-z0-9_.]{2,128}}';
$flag = '{code:[a-z0-9][a-z0-9_.-]{1,95}}';

return [
    Group::create('/settings')->middleware(RequirePermission::for('settings.view'))->routes(
        Route::get('')->action([S::class, 'index'])->name('admin.settings'),
        Route::post('')->middleware($manage)->action([S::class, 'save'])->name('admin.settings.save'),
        Route::post('/secure')->middleware($manage, RequireRecentAuth::class)->action([S::class, 'secure'])->name('admin.settings.secure'),

        Route::get('/flags')->action([F::class, 'index'])->name('admin.settings.flags'),
        Route::get("/flags/$flag")->action([F::class, 'show'])->name('admin.settings.flags.show'),
        Route::post("/flags/$flag/enable")->middleware($manage)->action([F::class, 'enable'])->name('admin.settings.flags.enable'),
        Route::post("/flags/$flag/disable")->middleware($manage)->action([F::class, 'disable'])->name('admin.settings.flags.disable'),
        Route::post("/flags/$flag/configure")->middleware($manage)->action([F::class, 'configure'])->name('admin.settings.flags.configure'),
        Route::post("/flags/$flag/override")->middleware($manage)->action([F::class, 'override'])->name('admin.settings.flags.override'),

        Route::get("/key/$key/history")->action([S::class, 'history'])->name('admin.settings.history'),
        Route::post("/key/$key/rollback")->middleware($manage, RequireRecentAuth::class)->action([S::class, 'rollback'])->name('admin.settings.rollback'),
        Route::post("/key/$key/reset")->middleware($manage, RequireRecentAuth::class)->action([S::class, 'reset'])->name('admin.settings.reset'),
    ),
];
