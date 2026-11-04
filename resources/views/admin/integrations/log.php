<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database\Sql;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $log
 */
$this->setTitle($h->tr('Call #{id}', ['id' => $log['id']]));
$this->setParameter('breadcrumbs', [$h->tr('Integrations') => $h->url('admin.integrations'), $h->tr('Recent calls') => $h->url('admin.integrations.logs'), '#' . $log['id']]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('Call #{id}', ['id' => $log['id']]) ?></h1>
    <p class="page-head__sub"><span class="tag"><?= $h->e($log['method']) ?></span> <span class="mono"><?= $h->e($log['url']) ?></span></p></div></div>
<div class="grid grid--2">
    <div class="card"><div class="card__head"><h2><?= $h->t('Summary') ?></h2></div><div class="card__body"><dl class="dl">
        <dt><?= $h->t('Integration') ?></dt><dd class="mono"><?= $h->e($log['integration_code'] ?? '(provider)') ?></dd>
        <dt><?= $h->t('Time') ?></dt><dd><?= $h->datetime($log['created_at'], 'Y-m-d H:i:s') ?></dd>
        <dt><?= $h->t('Correlation id') ?></dt><dd class="mono"><?= $h->e($log['correlation_id']) ?></dd>
        <dt><?= $h->t('HTTP status') ?></dt><dd><?= $h->e($log['status_code'] ?? '—') ?></dd>
        <dt><?= $h->t('Duration') ?></dt><dd><?= $h->e(($log['duration_ms'] ?? '—') . ' ms') ?></dd>
        <dt><?= $h->t('Attempt') ?></dt><dd><?= (int) $log['attempt'] ?></dd>
        <dt><?= $h->t('Error') ?></dt><dd><?= $log['error_class'] ? '<span class="tag">' . $h->e($log['error_class']) . '</span> ' . $h->e($log['error_message']) : '—' ?></dd>
    </dl></div></div>
    <div class="card"><div class="card__head"><h2><?= $h->t('Request headers (redacted)') ?></h2></div><div class="card__body"><pre><?= $h->json(Sql::json($log['request_headers'])) ?></pre></div></div>
    <div class="card"><div class="card__head"><h2><?= $h->t('Request body sample') ?></h2></div><div class="card__body"><pre><?= $h->e($log['request_sample'] ?? '—') ?></pre></div></div>
    <div class="card"><div class="card__head"><h2><?= $h->t('Response body sample') ?></h2></div><div class="card__body"><pre><?= $h->e($log['response_sample'] ?? '—') ?></pre></div></div>
</div>
