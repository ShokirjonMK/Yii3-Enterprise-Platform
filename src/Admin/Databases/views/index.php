<?php

declare(strict_types=1);

use App\Platform\Database\Connections\DatabaseDriver;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string, App\Platform\Database\Connections\ConnectionDefinition> $defs
 * @var array<string, int> $engines
 * @var array<string, ?string> $drivers driver => unavailable reason
 * @var callable(string):bool $can
 */
$this->setTitle($h->tr('Databases'));
$this->setParameter('breadcrumbs', [$h->tr('Databases')]);
$manage = $can('databases.manage');
$sources = ['system' => $h->tr('platform'), 'env' => '.env', 'panel' => $h->tr('panel')];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Databases') ?></h1>
        <p class="page-head__sub"><?= $h->t('All database connections of the platform — any engine, any number at the same time. Code uses them by name through DatabaseManager.') ?></p>
    </div>
    <?php if ($manage): ?>
    <div class="page-head__actions"><a class="btn btn--primary" href="<?= $h->url('admin.databases.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New connection') ?></a></div>
    <?php endif ?>
</div>

<div class="grid grid--4 mb">
    <div class="kpi"><div class="kpi__label"><?= $h->t('Connections') ?></div><div class="kpi__value"><?= count($defs) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= $h->t('Engines in use') ?></div><div class="kpi__value"><?= count($engines) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= $h->t('Enabled') ?></div><div class="kpi__value"><?= count(array_filter($defs, static fn($d) => $d->enabled)) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= $h->t('Read-only') ?></div><div class="kpi__value"><?= count(array_filter($defs, static fn($d) => $d->readOnly)) ?></div></div>
</div>

<div class="card mb">
    <div class="table-wrap"><table class="table">
        <thead><tr><th><?= $h->t('Name') ?></th><th><?= $h->t('Engine') ?></th><th><?= $h->t('Target') ?></th><th><?= $h->t('Source') ?></th><th><?= $h->t('Status') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($defs as $d): ?>
            <tr>
                <td class="nowrap"><a href="<?= $h->url('admin.databases.show', ['name' => $d->name]) ?>"><strong><?= $h->e($d->name) ?></strong></a>
                    <?php if ($d->description !== ''): ?><div class="small muted"><?= $h->e($d->description) ?></div><?php endif ?></td>
                <td class="nowrap"><?= $h->e(DatabaseDriver::label($d->driver)) ?><?= $d->readOnly ? ' ' . $h->badge('info', $h->tr('read-only')) : '' ?></td>
                <td class="mono small"><?= $h->e($d->target()) ?></td>
                <td><span class="tag"><?= $h->e($sources[$d->source] ?? $d->source) ?></span></td>
                <td class="nowrap"><?php if (!$d->enabled): ?><?= $h->badge('disabled') ?><?php else: ?>
                    <span hx-get="<?= $h->url('admin.databases.status', ['name' => $d->name]) ?>" hx-trigger="load" hx-swap="outerHTML"><span class="small muted"><?= $h->t('checking…') ?></span></span>
                <?php endif ?></td>
                <td class="actions nowrap">
                    <a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.databases.show', ['name' => $d->name]) ?>"><?= $h->icon('eye', 'icon--sm') ?></a>
                    <?php if ($can('databases.query') && $d->enabled): ?><a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.databases.console', ['name' => $d->name]) ?>" title="<?= $h->t('Query console') ?>"><?= $h->icon('code', 'icon--sm') ?></a><?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Supported engines') ?></h2></div>
    <div class="card__body">
        <div class="table-wrap"><table class="table">
            <thead><tr><th><?= $h->t('Engine') ?></th><th><?= $h->t('Use in code') ?></th><th><?= $h->t('Runtime') ?></th><th><?= $h->t('Connections') ?></th></tr></thead>
            <tbody>
            <?php foreach ($drivers as $driver => $reason): ?>
                <tr>
                    <td><?= $h->e(DatabaseDriver::label($driver)) ?></td>
                    <td class="mono small"><?= $h->e(match (DatabaseDriver::family($driver)) {
                        'document' => '$dbs->mongo(\'name\')', 'olap' => '$dbs->clickhouse(\'name\')', default => '$dbs->sql(\'name\')'
                    }) ?></td>
                    <td><?= $reason === null ? $h->badge('ok', $h->tr('ready')) : $h->badge('warning', $h->tr('not available')) . '<div class="small muted">' . $h->t($reason) . '</div>' ?></td>
                    <td><?= (int) ($engines[$driver] ?? 0) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <p class="small muted"><?= $h->t('Connections from .env (DB_CONNECTIONS, DB_CONN_<NAME>_URL) are shown read-only; connections added here are stored with the password encrypted. See docs/DATABASES.md.') ?></p>
    </div>
</div>
