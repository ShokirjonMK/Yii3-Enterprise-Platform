<?php

declare(strict_types=1);

/**
 * Admin · one row: all fields, where it is stored, how to reach it from the API and the console.
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
$this->setParameter('breadcrumbs', [
    $h->tr('Multi-DB demo') => $h->url('admin.multidb'),
    $h->tr($d->label) => $h->url('admin.multidb.list', ['dataset' => $d->code]),
    $title,
]);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($title) ?></h1>
        <p class="page-head__sub"><?= $h->e($d->engineLabel()) ?> · <span class="mono small"><?= $h->e($row['id']) ?></span></p>
    </div>
    <div class="page-head__actions">
        <?php if ($can('multidb.update')): ?>
            <a class="btn" href="<?= $h->url('admin.multidb.edit', $args) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
        <?php endif ?>
        <?php if ($can('multidb.delete')): ?>
            <form method="post" action="<?= $h->url('admin.multidb.delete', $args) ?>" data-confirm="<?= $h->t('Delete this {item}?', ['item' => $h->tr($d->singular)]) ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button>
            </form>
        <?php endif ?>
    </div>
</div>
<div class="grid grid--sidebar">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Details') ?></h2></div>
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
                <dt><?= $h->t('Created') ?></dt><dd><?= $h->e((string) ($row['created_at'] ?? '—')) ?></dd>
                <dt><?= $h->t('Updated') ?></dt><dd><?= $h->e((string) ($row['updated_at'] ?? '—')) ?></dd>
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Storage') ?></h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt><?= $h->t('Connection') ?></dt><dd><code><?= $h->e($d->connection) ?></code></dd>
                <dt><?= $h->t('Engine') ?></dt><dd><?= $h->e($d->engineLabel()) ?></dd>
                <dt><?= $h->t('Table / collection') ?></dt><dd><code><?= $h->e($d->table) ?></code></dd>
                <dt><?= $h->t('Id type') ?></dt><dd><code><?= $h->e($d->idType) ?></code></dd>
            </dl>
            <p class="small muted mt"><?= $h->t('API') ?>: <code>GET /api/v1/multidb/<?= $h->e($d->code) ?>/<?= $h->e($row['id']) ?></code></p>
            <p class="small muted"><?= $h->t('Console') ?>: <code>./yii multidb:crud <?= $h->e($d->code) ?> show <?= $h->e($row['id']) ?></code></p>
        </div>
    </div>
</div>
