<?php

declare(strict_types=1);

/**
 * Web · one record.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var callable(string):bool $can
 * @var App\Modules\MultiDb\Domain\Dataset $d
 * @var array<string,mixed> $row
 * @var string $title
 */

use App\Modules\MultiDb\Domain\Field;

$args = ['dataset' => $d->code, 'id' => $row['id']];
$this->setTitle($title);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($title) ?></h1>
        <p class="page-head__sub"><a href="<?= $h->url('web.multidb.list', ['dataset' => $d->code]) ?>"><?= $h->t($d->label) ?></a> · <?= $h->e($d->engineLabel()) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($can('multidb.update')): ?>
            <a class="btn" href="<?= $h->url('web.multidb.edit', $args) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
        <?php endif ?>
        <?php if ($can('multidb.delete')): ?>
            <form method="post" action="<?= $h->url('web.multidb.delete', $args) ?>" data-confirm="<?= $h->t('Delete this {item}?', ['item' => $h->tr($d->singular)]) ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button>
            </form>
        <?php endif ?>
    </div>
</div>
<div class="card">
    <div class="card__body">
        <dl class="dl">
            <?php foreach ($d->fields as $f): ?>
                <dt><?= $h->t($f->label) ?></dt>
                <dd>
                    <?php if ($f->type === Field::TEXT): ?>
                        <div class="ops-prewrap"><?= $h->e($f->display($row[$f->name])) ?></div>
                    <?php elseif ($f->type === Field::TAGS): ?>
                        <?= $row[$f->name] === [] ? '—' : implode('', array_map(static fn($t) => '<span class="tag">' . $h->e($t) . '</span>', $row[$f->name])) ?>
                    <?php else: ?>
                        <?= $h->e($h->tr($f->display($row[$f->name]))) ?>
                    <?php endif ?>
                </dd>
            <?php endforeach ?>
            <dt><?= $h->t('Updated') ?></dt><dd><?= $h->e((string) ($row['updated_at'] ?? '—')) ?></dd>
        </dl>
    </div>
</div>
