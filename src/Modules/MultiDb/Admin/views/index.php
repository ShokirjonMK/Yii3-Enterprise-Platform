<?php

declare(strict_types=1);

/**
 * Admin · Multi-DB demo overview: one card per dataset (connection, engine, rows, statistics computed by that database).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var callable(string):bool $can
 * @var list<array{dataset:App\Modules\MultiDb\Domain\Dataset, driver:?string, ok:bool, count:?int, stats:list<array{label:string,value:string}>, error:?string}> $overview
 */

$this->setTitle($h->tr('Multi-DB demo'));
$this->setParameter('breadcrumbs', [$h->tr('Business modules') => $h->urlOrNull('admin.modules'), $h->tr('Multi-DB demo')]);
$ok = count(array_filter($overview, static fn($o) => $o['ok']));
$rows = array_sum(array_map(static fn($o) => (int) $o['count'], $overview));
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Multi-DB demo') ?></h1>
        <p class="page-head__sub"><?= $h->t('One CRUD over five databases: MariaDB, two PostgreSQL servers, MongoDB and ClickHouse. The same data is available in Admin, on the website, in the REST API and in the console.') ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($can('databases.view')): ?>
            <a class="btn" href="<?= $h->url('admin.databases') ?>"><?= $h->icon('database') ?> <?= $h->t('Databases') ?></a>
        <?php endif ?>
        <?php if ($can('multidb.install')): ?>
            <form method="post" action="<?= $h->url('admin.multidb.install') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <button class="btn btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Install tables and sample data') ?></button>
            </form>
        <?php endif ?>
    </div>
</div>

<div class="grid grid--4 mb">
    <div class="card kpi"><div class="kpi__label"><?= $h->t('Datasets') ?></div><div class="kpi__value"><?= count($overview) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->t('Reachable') ?></div><div class="kpi__value"><?= $ok ?> / <?= count($overview) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->t('Engines') ?></div><div class="kpi__value"><?= count(array_unique(array_filter(array_column($overview, 'driver')))) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->t('Rows in total') ?></div><div class="kpi__value"><?= $rows ?></div></div>
</div>

<div class="grid grid--3">
    <?php foreach ($overview as $o): $d = $o['dataset']; ?>
        <div class="card" data-dataset="<?= $h->e($d->code) ?>">
            <div class="card__head">
                <h2><?= $h->icon($d->icon) ?> <?= $h->t($d->label) ?></h2>
                <?= $o['ok'] ? $h->badge('ok', $d->engineLabel()) : $h->badge('error', $h->tr('Unavailable')) ?>
            </div>
            <div class="card__body">
                <p class="small muted"><?= $h->t($d->description) ?></p>
                <dl class="dl">
                    <dt><?= $h->t('Connection') ?></dt><dd><code><?= $h->e($d->connection) ?></code><?= $o['driver'] ? ' · ' . $h->e($o['driver']) : '' ?></dd>
                    <dt><?= $h->t('Table / collection') ?></dt><dd><code><?= $h->e($d->table) ?></code></dd>
                    <dt><?= $h->t('Rows') ?></dt><dd><?= $o['count'] === null ? '—' : (int) $o['count'] ?></dd>
                    <?php foreach ($o['stats'] as $s): ?>
                        <dt><?= $h->t($s['label']) ?></dt><dd><?= $h->e($s['value']) ?></dd>
                    <?php endforeach ?>
                </dl>
                <?php if ($o['error'] !== null): ?>
                    <div class="alert alert--error small"><?= $h->e($o['error']) ?></div>
                <?php elseif ($o['count'] === 0 && $can('multidb.install')): ?>
                    <div class="alert alert--info small"><?= $h->t('Empty — use "Install tables and sample data".') ?></div>
                <?php endif ?>
            </div>
            <div class="card__foot row">
                <a class="btn btn--sm" href="<?= $h->url('admin.multidb.list', ['dataset' => $d->code]) ?>"><?= $h->icon('eye') ?> <?= $h->t('Open') ?></a>
                <span class="small muted mono">GET /api/v1/multidb/<?= $h->e($d->code) ?></span>
            </div>
        </div>
    <?php endforeach ?>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Same data, four interfaces') ?></h2></div>
    <div class="card__body">
        <ul class="small">
            <li><b><?= $h->t('Admin') ?></b> — <?= $h->t('this section: full CRUD, search, filters, sorting.') ?></li>
            <li><b><?= $h->t('Website') ?></b> — <a href="<?= $h->e($h->urlOrNull('web.multidb') ?? '/multidb') ?>">/multidb</a> · <?= $h->t('cards for signed-in users (create/edit with permission).') ?></li>
            <li><b>REST API</b> — <code>/api/v1/multidb/{dataset}</code> · <?= $h->t('scopes multidb.read / multidb.write, documented in Swagger.') ?></li>
            <li><b><?= $h->t('Console') ?></b> — <code>./yii multidb:install</code>, <code>./yii multidb:crud products list --q=phone</code></li>
        </ul>
    </div>
</div>
