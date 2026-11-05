<?php

declare(strict_types=1);

use App\Admin\Audit\AuditController;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 20 · Audit & Compliance (route name admin.audit is linked from the dashboard). */
return [
    Group::create('/audit')->middleware(RequirePermission::for('audit.view'))->routes(
        Route::get('')->action([AuditController::class, 'index'])->name('admin.audit'),
        Route::get('/export.csv')->middleware(RequirePermission::for('audit.export'))->action([AuditController::class, 'export'])->name('admin.audit.export'),
        Route::get('/integrity')->action([AuditController::class, 'partitions'])->name('admin.audit.partitions'),
        Route::post('/integrity')->middleware(RequirePermission::for('audit.export'))->action([AuditController::class, 'verify'])->name('admin.audit.verify'),
        Route::get('/activity')->action([App\Admin\Audit\ActivityController::class, 'index'])->name('admin.audit.activity'),
        Route::get('/{id:\d+}')->action([AuditController::class, 'view'])->name('admin.audit.view'),
    ),
];
