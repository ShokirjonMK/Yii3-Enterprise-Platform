<?php

declare(strict_types=1);

/**
 * Admin · create / edit a row of any dataset (fields generated from the dataset definition).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var App\Modules\MultiDb\Domain\Dataset $d
 * @var array<string,mixed>|null $row
 * @var string|null $title
 * @var array<string,string> $values
 * @var array<string, list<string>> $errors
 */

$editing = $row !== null;
$args = ['dataset' => $d->code];
$heading = $editing ? $h->tr('Edit {item}', ['item' => (string) $title]) : $h->tr('New {item}', ['item' => $h->tr($d->singular)]);
$this->setTitle($heading);
$this->setParameter('breadcrumbs', array_filter([
    $h->tr('Multi-DB demo') => $h->url('admin.multidb'),
    $h->tr($d->label) => $h->url('admin.multidb.list', $args),
    ...($editing ? [(string) $title => $h->url('admin.multidb.view', $args + ['id' => $row['id']])] : []),
    $heading,
]));
$action = $editing ? $h->url('admin.multidb.update', $args + ['id' => $row['id']]) : $h->url('admin.multidb.store', $args);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($heading) ?></h1>
        <p class="page-head__sub"><?= $h->t('Saved to {engine} connection "{c}", {table}', ['engine' => $d->engineLabel(), 'c' => $d->connection, 'table' => $d->table]) ?></p>
    </div>
</div>
<div class="card">
    <div class="card__body">
        <form class="form form--2" method="post" action="<?= $h->e($action) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <?= $this->render('./_fields', ['d' => $d, 'values' => $values, 'errors' => $errors]) ?>
            <div class="row span-2">
                <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $editing ? $h->t('Save') : $h->t('Create') ?></button>
                <a class="btn btn--ghost" data-back href="<?= $editing ? $h->url('admin.multidb.view', $args + ['id' => $row['id']]) : $h->url('admin.multidb.list', $args) ?>"><?= $h->t('Cancel') ?></a>
            </div>
        </form>
    </div>
</div>
