<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $log
 */

$this->setTitle($h->tr('Log record'));
$this->setParameter('breadcrumbs', [$h->tr('Application logs') => $h->url('admin.monitoring.logs'), '#' . $log['id']]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('Log record') ?> #<?= (int) $log['id'] ?></h1></div></div>
<div class="card">
    <div class="card__body">
        <dl class="dl">
            <dt><?= $h->t('Time') ?></dt><dd><?= $h->datetime($log['occurred_at'], 'Y-m-d H:i:s') ?></dd>
            <dt><?= $h->t('Level') ?></dt><dd><?= $h->e($log['level']) ?></dd>
            <dt><?= $h->t('Category') ?></dt><dd class="mono"><?= $h->e($log['category']) ?></dd>
            <dt><?= $h->t('Process') ?></dt><dd><?= $h->e($log['channel']) ?> · <?= $h->e($log['host']) ?></dd>
            <dt><?= $h->t('Correlation id') ?></dt><dd class="mono"><a href="<?= $h->e($h->url('admin.audit.activity', [], ['path' => ''])) ?>"><?= $h->e($log['correlation_id']) ?></a></dd>
            <dt><?= $h->t('Message') ?></dt><dd><?= nl2br($h->e($log['message'])) ?></dd>
        </dl>
        <h3 class="mt"><?= $h->t('Context') ?></h3>
        <pre><?= $h->json(json_decode((string) $log['context'], true)) ?></pre>
    </div>
</div>
