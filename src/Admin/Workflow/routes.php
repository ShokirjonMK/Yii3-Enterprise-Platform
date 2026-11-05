<?php

declare(strict_types=1);

use App\Admin\Workflow\WorkflowController as W;
use App\Web\Middleware\RequirePermission;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

$manage = RequirePermission::for('workflow.manage');
$decide = RequirePermission::for('approvals.decide');
$uuid = '{id:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}}';

return [
    Group::create('/workflow')->middleware(RequirePermission::for('workflow.view', 'approvals.decide'))->routes(
        Route::get('')->action([W::class, 'index'])->name('admin.workflow'),
        Route::get('/new')->middleware($manage)->action([W::class, 'create'])->name('admin.workflow.create'),
        Route::post('')->middleware($manage)->action([W::class, 'store'])->name('admin.workflow.store'),
        Route::post('/validate')->action([W::class, 'validate'])->name('admin.workflow.validate'),

        Route::get('/instances')->action([W::class, 'instances'])->name('admin.workflow.instances'),
        Route::get("/instances/$uuid")->action([W::class, 'instance'])->name('admin.workflow.instance'),
        Route::post("/instances/$uuid/apply")->action([W::class, 'apply'])->name('admin.workflow.apply'),

        Route::get('/approvals')->action([W::class, 'approvals'])->name('admin.workflow.approvals'),
        Route::get("/approvals/$uuid")->action([W::class, 'approval'])->name('admin.workflow.approval'),
        Route::post("/approvals/$uuid/decide")->middleware($decide)->action([W::class, 'decide'])->name('admin.workflow.decide'),
        Route::post("/approvals/$uuid/cancel")->action([W::class, 'cancel'])->name('admin.workflow.approval_cancel'),

        Route::get("/$uuid")->action([W::class, 'show'])->name('admin.workflow.show'),
        Route::get("/$uuid/edit")->middleware($manage)->action([W::class, 'edit'])->name('admin.workflow.edit'),
        Route::post("/$uuid")->middleware($manage)->action([W::class, 'update'])->name('admin.workflow.update'),
        Route::post("/$uuid/delete")->middleware($manage)->action([W::class, 'delete'])->name('admin.workflow.delete'),
        Route::post("/$uuid/start")->middleware($manage)->action([W::class, 'start'])->name('admin.workflow.start'),
    ),
];
