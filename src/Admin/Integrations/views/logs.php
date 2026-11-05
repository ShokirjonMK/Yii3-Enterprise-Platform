<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $query
 * @var list<string> $codes
 */
$this->setTitle($h->tr('Recent integration calls'));
$this->setParameter('breadcrumbs', [$h->tr('Integrations') => $h->url('admin.integrations'), $h->tr('Recent calls')]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('Integrations') ?></h1>
    <p class="page-head__sub"><?= $h->t('Every outbound attempt (including notification providers) with redacted headers and body samples.') ?></p></div></div>
<?= $this->render('./_tabs', ['active' => 'logs']) ?>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.integrations.logs') ?>" hx-get="<?= $h->url('admin.integrations.logs') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change">
        <select name="integration" aria-label="<?= $h->t('Integration') ?>">
            <option value=""><?= $h->t('All integrations') ?></option>
            <?php foreach ($codes as $c): ?><option value="<?= $h->e($c) ?>"<?= ($query['integration'] ?? '') === $c ? ' selected' : '' ?>><?= $h->e($c) ?></option><?php endforeach ?>
        </select>
        <select name="outcome" aria-label="<?= $h->t('Outcome') ?>">
            <option value=""><?= $h->t('All outcomes') ?></option>
            <option value="ok"<?= ($query['outcome'] ?? '') === 'ok' ? ' selected' : '' ?>><?= $h->t('Successful') ?></option>
            <option value="error"<?= ($query['outcome'] ?? '') === 'error' ? ' selected' : '' ?>><?= $h->t('Errors') ?></option>
        </select>
        <noscript><button class="btn" type="submit"><?= $h->t('Filter') ?></button></noscript>
    </form>
    <?= $this->render('./_logs-table', ['page' => $page, 'query' => $query, 'route' => 'admin.integrations.logs', 'args' => [], 'showIntegration' => true]) ?>
</div>
