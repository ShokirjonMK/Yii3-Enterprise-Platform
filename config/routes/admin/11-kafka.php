<?php

declare(strict_types=1);

use App\Admin\Action\Kafka\KafkaController;
use App\Admin\Action\Kafka\KafkaToolsController;
use App\Web\Middleware\RequirePermission;
use App\Web\Middleware\RequireRecentAuth;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** 11 · Kafka Management — kafka.view / kafka.manage; replay, re-queue and unregister require step-up re-auth. */
return (static function (): array {
    $manage = RequirePermission::for('kafka.manage');
    $topic = '{name:[a-z0-9][a-z0-9._-]{0,200}}';
    $group = '{group:[A-Za-z0-9._-]{1,128}}';

    return [
        Group::create('/kafka')->middleware(RequirePermission::for('kafka.view'))->routes(
            Route::get('')->action([KafkaController::class, 'index'])->name('admin.kafka'),
            Route::get('/topics/new')->middleware($manage)->action([KafkaController::class, 'topicCreate'])->name('admin.kafka.topics.create'),
            Route::post('/topics/new')->middleware($manage)->action([KafkaController::class, 'topicStore'])->name('admin.kafka.topics.store'),
            Route::get('/topics/' . $topic . '/edit')->middleware($manage)->action([KafkaController::class, 'topicEdit'])->name('admin.kafka.topics.edit'),
            Route::post('/topics/' . $topic . '/edit')->middleware($manage)->action([KafkaController::class, 'topicUpdate'])->name('admin.kafka.topics.update'),
            Route::post('/topics/' . $topic . '/delete')->middleware($manage, RequireRecentAuth::class)->action([KafkaController::class, 'topicDelete'])->name('admin.kafka.topics.delete'),
            Route::get('/provision')->action([KafkaController::class, 'provision'])->name('admin.kafka.provision'),
            Route::get('/provision/download')->action([KafkaController::class, 'provisionDownload'])->name('admin.kafka.provision.download'),

            Route::get('/consumers')->action([KafkaController::class, 'consumers'])->name('admin.kafka.consumers'),
            Route::get('/handlers')->action([KafkaToolsController::class, 'handlers'])->name('admin.kafka.handlers'),
            Route::get('/publish')->action([KafkaToolsController::class, 'publishForm'])->name('admin.kafka.publish'),
            Route::post('/publish')->middleware($manage, RequireRecentAuth::class)->action([KafkaToolsController::class, 'publish'])->name('admin.kafka.publish.send'),
            Route::get('/settings')->action([KafkaToolsController::class, 'settings'])->name('admin.kafka.settings'),
            Route::post('/settings')->middleware($manage, RequireRecentAuth::class)->action([KafkaToolsController::class, 'saveSettings'])->name('admin.kafka.settings.save'),
            Route::get('/consumers/' . $group . '/lag')->action([KafkaController::class, 'lag'])->name('admin.kafka.consumers.lag'),
            Route::post('/consumers/' . $group . '/pause')->middleware($manage)->action([KafkaController::class, 'pauseConsumer'])->name('admin.kafka.consumers.pause'),

            Route::get('/outbox')->action([KafkaController::class, 'outbox'])->name('admin.kafka.outbox'),
            Route::post('/outbox/requeue')->middleware($manage, RequireRecentAuth::class)->action([KafkaController::class, 'outboxRequeueAll'])->name('admin.kafka.outbox.requeue_all'),
            Route::post('/outbox/{id:[0-9a-fA-F-]{36}}/requeue')->middleware($manage, RequireRecentAuth::class)->action([KafkaController::class, 'outboxRequeue'])->name('admin.kafka.outbox.requeue'),

            Route::get('/dead-letters')->action([KafkaController::class, 'deadLetters'])->name('admin.kafka.dlq'),
            Route::get('/dead-letters/{id:\d+}')->action([KafkaController::class, 'deadLetter'])->name('admin.kafka.dlq.view'),
            Route::post('/dead-letters/{id:\d+}/replay')->middleware($manage, RequireRecentAuth::class)->action([KafkaController::class, 'replay'])->name('admin.kafka.dlq.replay'),
        ),
    ];
})();
