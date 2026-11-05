<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var list<string> $levels
 * @var array<string,int> $counts
 * @var bool $enabled
 */

$this->setTitle($h->tr('Application logs'));
$this->setParameter('breadcrumbs', [$h->tr('Monitoring & Observability') => $h->url('admin.monitoring'), $h->tr('Application logs')]);
$query = array_filter($filters, static fn($v) => $v !== '');
$tone = static fn(string $l) => in_array($l, ['emergency', 'alert', 'critical', 'error'], true) ? 'error' : 'warning';
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Application logs') ?></h1>
        <p class="page-head__sub"><?= $h->t('Warnings and errors from web, API, workers and consumers (secrets and personal data are masked).') ?></p>
    </div>
    <div class="page-head__actions"><a class="btn" href="<?= $h->url('admin.monitoring') ?>"><?= $h->icon('activity') ?> <?= $h->t('Monitoring') ?></a></div>
</div>
<?php if (!$enabled): ?>
    <div class="alert alert--warning"><?= $h->t('Storing logs in the database is switched off (feature flag logging.database). JSON logs are still written to stderr.') ?></div>
<?php endif ?>
<div class="grid grid--4 mb">
    <?php foreach (['critical', 'error', 'warning'] as $l): ?>
        <div class="card kpi"><div class="kpi__label"><?= $h->icon('alert') ?> <?= $h->e(ucfirst($l)) ?> (24 h)</div><div class="kpi__value"><?= (int) ($counts[$l] ?? 0) ?></div></div>
    <?php endforeach ?>
</div>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.monitoring.logs') ?>" hx-get="<?= $h->url('admin.monitoring.logs') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change, submit, keyup changed delay:400ms from:input[type=search]">
        <select name="level" aria-label="<?= $h->t('Level') ?>"><option value=""><?= $h->t('All levels') ?></option>
            <?php foreach ($levels as $l): ?><option value="<?= $l ?>"<?= $filters['level'] === $l ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
        <select name="channel" aria-label="<?= $h->t('Channel') ?>"><option value=""><?= $h->t('All processes') ?></option>
            <?php foreach (['web', 'api', 'worker', 'consumer', 'cli'] as $c): ?><option value="<?= $c ?>"<?= $filters['channel'] === $c ? ' selected' : '' ?>><?= $c ?></option><?php endforeach ?></select>
        <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Search message') ?>" aria-label="<?= $h->t('Search') ?>">
        <input type="search" name="correlation" value="<?= $h->e($filters['correlation']) ?>" placeholder="<?= $h->t('Correlation id') ?>" aria-label="<?= $h->t('Correlation id') ?>">
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('check') ?><p><?= $h->t('No warnings or errors.') ?></p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Time') ?></th><th><?= $h->t('Level') ?></th><th><?= $h->t('Process') ?></th><th><?= $h->t('Message') ?></th><th><?= $h->t('Correlation id') ?></th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $l): ?>
            <tr>
                <td class="nowrap small"><a href="<?= $h->url('admin.monitoring.logs.view', ['id' => $l['id']]) ?>"><?= $h->datetime($l['occurred_at'], 'Y-m-d H:i:s') ?></a></td>
                <td><?= $h->badge($tone((string) $l['level']), (string) $l['level']) ?></td>
                <td class="small"><?= $h->e($l['channel']) ?> <span class="muted"><?= $h->e($l['host']) ?></span></td>
                <td><?= $h->e(mb_strimwidth((string) $l['message'], 0, 160, '…')) ?></td>
                <td class="mono small"><?php if ($l['correlation_id']): ?><a href="<?= $h->e($h->url('admin.monitoring.logs', [], ['correlation' => $l['correlation_id']])) ?>"><?= $h->e(substr((string) $l['correlation_id'], 0, 12)) ?></a><?php endif ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.monitoring.logs', 'query' => $query]) ?>
    <?php endif ?>
</div>
