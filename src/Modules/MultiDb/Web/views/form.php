<?php

declare(strict_types=1);

/**
 * Web · create / edit a record (fields shared with Admin).
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
$action = $editing ? $h->url('web.multidb.update', $args + ['id' => $row['id']]) : $h->url('web.multidb.store', $args);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($heading) ?></h1></div></div>
<div class="card">
    <div class="card__body">
        <form class="form form--2" method="post" action="<?= $h->e($action) ?>" novalidate>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <?= $this->render($h->view('@modules/MultiDb/Admin/views/_fields'), ['d' => $d, 'values' => $values, 'errors' => $errors]) ?>
            <div class="row span-2">
                <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $editing ? $h->t('Save') : $h->t('Create') ?></button>
                <a class="btn btn--ghost" href="<?= $editing ? $h->url('web.multidb.view', $args + ['id' => $row['id']]) : $h->url('web.multidb.list', $args) ?>"><?= $h->t('Cancel') ?></a>
            </div>
        </form>
    </div>
</div>
