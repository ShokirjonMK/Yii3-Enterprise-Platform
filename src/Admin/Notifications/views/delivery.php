<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database\Sql;
use App\Shared\Infrastructure\Logging\Redactor;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $n
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($h->tr('Delivery'));
$this->setParameter('breadcrumbs', [$h->tr('Messaging & Notifications') => $h->url('admin.notifications'), $h->tr('Deliveries') => $h->url('admin.notifications.deliveries'), substr((string) $n['id'], 0, 8)]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($n['subject'] ?: $n['template_code']) ?></h1>
    <p class="page-head__sub row"><span class="tag"><?= $h->e($n['channel']) ?></span> <?= $h->badge($n['status']) ?></p></div>
    <?php if ($can('notifications.manage') && $n['status'] === 'failed'): ?>
    <div class="page-head__actions"><form method="post" action="<?= $h->url('admin.notifications.retry', ['id' => $n['id']]) ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <button class="btn btn--primary" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Retry delivery') ?></button></form></div>
    <?php endif ?>
</div>
<div class="grid grid--2">
    <div class="card"><div class="card__body"><dl class="dl">
        <dt><?= $h->t('User') ?></dt><dd><?= $h->e($n['username'] ?? '—') ?></dd>
        <dt><?= $h->t('Recipient') ?></dt><dd class="mono"><?= $h->e($n['recipient']) ?></dd>
        <dt><?= $h->t('Template') ?></dt><dd class="mono"><?= $h->e($n['template_code']) ?></dd>
        <dt><?= $h->t('Category') ?></dt><dd><?= $h->e($n['category']) ?></dd>
        <dt><?= $h->t('Created') ?></dt><dd><?= $h->datetime($n['created_at'], 'Y-m-d H:i:s') ?></dd>
        <dt><?= $h->t('Sent') ?></dt><dd><?= $h->datetime($n['sent_at'], 'Y-m-d H:i:s') ?></dd>
        <dt><?= $h->t('Attempts') ?></dt><dd><?= (int) $n['attempts'] ?></dd>
        <dt><?= $h->t('Provider id') ?></dt><dd class="mono"><?= $h->e($n['provider_message_id'] ?? '—') ?></dd>
        <dt><?= $h->t('Job') ?></dt><dd class="mono"><?= $h->e($n['job_id'] ?? '—') ?></dd>
        <dt><?= $h->t('Last error') ?></dt><dd><?= $h->e($n['last_error'] ?? '—') ?></dd>
    </dl></div></div>
    <div class="stack">
        <div class="card"><div class="card__head"><h2><?= $h->t('Body') ?></h2></div><div class="card__body"><pre><?= $h->e($n['body']) ?></pre></div></div>
        <div class="card"><div class="card__head"><h2><?= $h->t('Parameters (redacted)') ?></h2></div><div class="card__body"><pre><?= $h->json(Redactor::redact(Sql::json($n['data']))) ?></pre></div></div>
    </div>
</div>
