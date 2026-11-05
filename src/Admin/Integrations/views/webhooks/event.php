<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $row
 * @var array $event payload already redacted
 */
$this->setTitle($h->tr('Webhook event #{id}', ['id' => $event['id']]));
$this->setParameter('breadcrumbs', [$h->tr('Inbound webhooks') => $h->url('admin.integrations.webhooks'), $row['code'] => $h->url('admin.integrations.webhooks.show', ['id' => $row['id']]), '#' . $event['id']]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('Webhook event #{id}', ['id' => $event['id']]) ?></h1></div></div>
<div class="grid grid--sidebar">
    <div class="card"><div class="card__head"><h2><?= $h->t('Payload (redacted)') ?></h2></div><div class="card__body"><pre><?= $h->json($event['payload']) ?></pre></div></div>
    <div class="card"><div class="card__body"><dl class="dl">
        <dt><?= $h->t('External id') ?></dt><dd class="mono"><?= $h->e($event['external_id']) ?></dd>
        <dt><?= $h->t('Received') ?></dt><dd><?= $h->datetime($event['received_at'], 'Y-m-d H:i:s') ?></dd>
        <dt><?= $h->t('Processed') ?></dt><dd><?= $h->datetime($event['processed_at'], 'Y-m-d H:i:s') ?></dd>
        <dt><?= $h->t('Job') ?></dt><dd class="mono"><?= $h->e($event['job_id'] ?? '—') ?><?php if ($event['job_id'] && $h->urlOrNull('admin.jobs.show', ['id' => $event['job_id']])): ?> <a href="<?= $h->url('admin.jobs.show', ['id' => $event['job_id']]) ?>"><?= $h->t('open') ?></a><?php endif ?></dd>
    </dl></div></div>
</div>
