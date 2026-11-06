<?php

declare(strict_types=1);

/**
 * Admin · one dataset: statistics, search / filters (HTMX), sortable table, paging.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var callable(string):bool $can
 * @var App\Modules\MultiDb\Domain\Dataset $d
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var list<array{label:string,value:string}> $stats
 * @var string $q
 * @var array<string,string> $filters
 * @var array<string,string> $query
 * @var string|null $error
 */

use App\Modules\MultiDb\Domain\Field;

$this->setTitle($h->tr($d->label));
$this->setParameter('breadcrumbs', [$h->tr('Multi-DB demo') => $h->url('admin.multidb'), $h->tr($d->label)]);
$hx = 'hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"';
$args = ['dataset' => $d->code];
$cell = static function (Field $f, mixed $value) use ($h): string {
    $v = $f->present($value);
    return match ($f->type) {
        Field::TAGS => $v === [] ? '—' : implode('', array_map(static fn($t) => '<span class="tag">' . $h->e($t) . '</span>', $v)),
        Field::BOOL => $h->badge($v ? 'ok' : 'muted', $h->tr($v ? 'Yes' : 'No')),
        Field::ENUM => '<span class="tag">' . $h->e($v) . '</span>',
        default => $h->e($f->display($value)),
    };
};
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->icon($d->icon) ?> <?= $h->t($d->label) ?></h1>
        <p class="page-head__sub"><?= $h->t($d->description) ?> · <code><?= $h->e($d->connection) ?></code> / <code><?= $h->e($d->table) ?></code></p>
    </div>
    <div class="page-head__actions">
        <?php if ($can('multidb.create')): ?>
            <a class="btn btn--primary" href="<?= $h->url('admin.multidb.create', $args) ?>"><?= $h->icon('plus') ?> <?= $h->t('New {item}', ['item' => $h->tr($d->singular)]) ?></a>
        <?php endif ?>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert--error mb" role="alert"><?= $h->t('The "{c}" database is not available.', ['c' => $d->connection]) ?> <span class="small"><?= $h->e($error) ?></span></div>
<?php endif ?>

<?php if ($stats !== []): ?>
<div class="grid grid--4 mb" data-stats>
    <?php foreach (array_slice($stats, 0, 8) as $s): ?>
        <div class="card kpi"><div class="kpi__label"><?= $h->t($s['label']) ?></div><div class="kpi__value"><?= $h->e($s['value']) ?></div></div>
    <?php endforeach ?>
</div>
<?php endif ?>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.multidb.list', $args) ?>" hx-get="<?= $h->url('admin.multidb.list', $args) ?>" <?= $hx ?> hx-trigger="submit, change from:select, search from:input[type=search]">
        <input type="search" name="q" value="<?= $h->e($q) ?>" placeholder="<?= $h->t('Search…') ?>" aria-label="<?= $h->t('Search') ?>">
        <?php foreach ($d->filters as $name): $f = $d->field($name); ?>
            <select name="<?= $h->e($name) ?>" aria-label="<?= $h->t($f->label) ?>">
                <option value=""><?= $h->t('Any') ?> · <?= $h->t($f->label) ?></option>
                <?php foreach ($f->type === Field::BOOL ? ['1' => 'Yes', '0' => 'No'] : array_combine($f->options, $f->options) as $val => $lbl): ?>
                    <option value="<?= $h->e((string) $val) ?>"<?= ($filters[$name] ?? '') === (string) $val ? ' selected' : '' ?>><?= $h->t((string) $lbl) ?></option>
                <?php endforeach ?>
            </select>
        <?php endforeach ?>
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>

    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('folder') ?><p><?= $h->t('Nothing found.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table" id="multidb-table">
            <thead><tr>
                <?php foreach ($d->columns as $c): $f = $d->field($c); ?>
                    <th<?= in_array($f->type, [Field::INT, Field::DECIMAL], true) ? ' class="right"' : '' ?>>
                        <?= in_array($c, $d->sortable(), true) ? $h->sortLink($h->tr($f->label), $c, $page->sort, $query, 'admin.multidb.list', $args) : $h->t($f->label) ?>
                    </th>
                <?php endforeach ?>
                <th><?= $h->sortLink($h->tr('Updated'), 'updated_at', $page->sort, $query, 'admin.multidb.list', $args) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $r): ?>
                <tr>
                    <?php foreach ($d->columns as $i => $c): $f = $d->field($c); ?>
                        <td<?= in_array($f->type, [Field::INT, Field::DECIMAL], true) ? ' class="right mono"' : '' ?>>
                            <?php if ($i === 0): ?>
                                <a href="<?= $h->url('admin.multidb.view', $args + ['id' => $r['id']]) ?>"><?= $cell($f, $r[$c]) ?></a>
                            <?php else: ?>
                                <?= $cell($f, $r[$c]) ?>
                            <?php endif ?>
                        </td>
                    <?php endforeach ?>
                    <td class="nowrap small muted"><?= $h->e((string) ($r['updated_at'] ?? '—')) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.multidb.list', 'query' => $query, 'args' => $args]) ?>
</div>
