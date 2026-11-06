<?php

declare(strict_types=1);

/**
 * Web · one dataset as a card grid with search (HTMX) and paging.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var App\Modules\MultiDb\Domain\Dataset $d
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var string $q
 * @var array<string,string> $query
 * @var array<string, App\Modules\MultiDb\Domain\Dataset> $datasets
 */

use App\Modules\MultiDb\Domain\Field;

$this->setTitle($h->tr($d->label));
$args = ['dataset' => $d->code];
$hx = 'hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"';
$show = static function (Field $f, mixed $v) use ($h): string {
    if ($f->type === Field::TAGS) {
        return $v === [] ? '—' : implode('', array_map(static fn($t) => '<span class="tag">' . $h->e($t) . '</span>', (array) $v));
    }
    return $h->e($h->tr($f->display($v)));
};
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->icon($d->icon) ?> <?= $h->t($d->label) ?></h1>
        <p class="page-head__sub"><a href="<?= $h->url('web.multidb') ?>"><?= $h->t('Data sources') ?></a> · <?= $h->e($d->engineLabel()) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($can('multidb.create')): ?>
            <a class="btn btn--primary" href="<?= $h->url('web.multidb.create', $args) ?>"><?= $h->icon('plus') ?> <?= $h->t('New {item}', ['item' => $h->tr($d->singular)]) ?></a>
        <?php endif ?>
    </div>
</div>
<div class="row mb">
    <?php foreach ($datasets as $other): ?>
        <a class="btn btn--sm<?= $other->code === $d->code ? ' btn--primary' : ' btn--ghost' ?>" href="<?= $h->url('web.multidb.list', ['dataset' => $other->code]) ?>"<?= $other->code === $d->code ? ' aria-current="page"' : '' ?>><?= $h->t($other->label) ?></a>
    <?php endforeach ?>
</div>
<form class="toolbar mb" method="get" action="<?= $h->url('web.multidb.list', $args) ?>" hx-get="<?= $h->url('web.multidb.list', $args) ?>" <?= $hx ?> hx-trigger="submit, search from:input[type=search]">
    <input type="search" name="q" value="<?= $h->e($q) ?>" placeholder="<?= $h->t('Search…') ?>" aria-label="<?= $h->t('Search') ?>">
    <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Search') ?></button>
</form>
<?php if ($page->items === []): ?>
    <div class="card"><div class="empty"><?= $h->icon('folder') ?><p><?= $h->t('Nothing found.') ?></p></div></div>
<?php else: ?>
    <div class="grid grid--3" id="multidb-cards">
        <?php foreach ($page->items as $r): ?>
            <a class="card" href="<?= $h->url('web.multidb.view', $args + ['id' => $r['id']]) ?>">
                <div class="card__head"><h2><?= $h->e($d->field($d->titleField)?->display($r[$d->titleField]) ?? $r['id']) ?></h2></div>
                <div class="card__body">
                    <dl class="dl">
                        <?php foreach (array_slice(array_values(array_diff($d->columns, [$d->titleField])), 0, 4) as $c): ?>
                            <dt><?= $h->t($d->field($c)->label) ?></dt><dd><?= $show($d->field($c), $r[$c]) ?></dd>
                        <?php endforeach ?>
                    </dl>
                </div>
            </a>
        <?php endforeach ?>
    </div>
<?php endif ?>
<?= $this->render('//components/pager', ['page' => $page, 'route' => 'web.multidb.list', 'query' => $query, 'args' => $args]) ?>
