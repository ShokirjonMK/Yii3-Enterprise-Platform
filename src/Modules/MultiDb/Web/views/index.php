<?php

declare(strict_types=1);

/**
 * Web · data sources: one tile per dataset (each lives in a different database).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array{dataset:App\Modules\MultiDb\Domain\Dataset, driver:?string, ok:bool, count:?int, stats:array, error:?string}> $overview
 */

$this->setTitle($h->tr('Data sources'));
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Data sources') ?></h1>
        <p class="page-head__sub"><?= $h->t('Each catalogue below is stored in a different database engine.') ?></p>
    </div>
</div>
<div class="grid grid--3">
    <?php foreach ($overview as $o): $d = $o['dataset']; ?>
        <a class="card" href="<?= $h->url('web.multidb.list', ['dataset' => $d->code]) ?>" data-dataset="<?= $h->e($d->code) ?>">
            <div class="card__head"><h2><?= $h->icon($d->icon) ?> <?= $h->t($d->label) ?></h2><?= $o['ok'] ? $h->badge('ok', $d->engineLabel()) : $h->badge('error', $h->tr('Unavailable')) ?></div>
            <div class="card__body">
                <p class="small muted"><?= $h->t($d->description) ?></p>
                <div class="kpi__value"><?= $o['count'] === null ? '—' : (int) $o['count'] ?></div>
                <div class="small muted"><?= $h->t('records') ?></div>
            </div>
        </a>
    <?php endforeach ?>
</div>
