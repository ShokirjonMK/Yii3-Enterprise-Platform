<?php

declare(strict_types=1);

use App\Admin\Action\Settings\AppearanceController as A;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 21 · System Settings → Appearance (design themes). Author: ShokirjonMK */
$manage = RequirePermission::for('settings.manage');
$code = '{code:[a-z0-9][a-z0-9-]{1,39}}';

return [
    Group::create('/settings/appearance')->middleware(RequirePermission::for('settings.view'))->routes(
        Route::get('')->action([A::class, 'index'])->name('admin.appearance'),
        Route::post('/activate')->middleware($manage, RequireRecentAuth::class)->action([A::class, 'activate'])->name('admin.appearance.activate'),
        Route::post('/tenant')->middleware($manage, RequireRecentAuth::class)->action([A::class, 'tenant'])->name('admin.appearance.tenant'),
        Route::post('/user-choice')->middleware($manage)->action([A::class, 'userChoice'])->name('admin.appearance.user_choice'),
        Route::post('/upload')->middleware($manage, RequireRecentAuth::class)->action([A::class, 'upload'])->name('admin.appearance.upload'),
        Route::post('/preview/stop')->action([A::class, 'stopPreview'])->name('admin.appearance.preview.stop'),
        Route::post("/$code/preview")->action([A::class, 'preview'])->name('admin.appearance.preview'),
        Route::get("/$code/download")->action([A::class, 'download'])->name('admin.appearance.download'),
        Route::post("/$code/delete")->middleware($manage, RequireRecentAuth::class)->action([A::class, 'delete'])->name('admin.appearance.delete'),
    ),
];
