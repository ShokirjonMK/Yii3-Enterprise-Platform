<?php

declare(strict_types=1);

/**
 * Template: web list view (src/Modules/<Module>/Web/views/index.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$statusEnum = '\\App\\Modules\\' . $m->module . '\\Domain\\' . $m->entity . 'Status';
$display = $m->displayField();
$listed = array_slice(array_values(array_filter($m->fields, static fn($f) => $f->isListed() && $f !== $display)), 0, 5);
$heads = ['<th><?= $h->t(' . $q($display?->label() ?? 'Id') . ') ?></th>'];
$linkLabel = $display !== null ? "\$h->e((string) (\$r[" . $q($display->name) . "] ?? ''))" : "\$h->e(substr((string) \$r['id'], 0, 8))";
$cells = ["<td><a href=\"<?= \$h->url('web.{{code}}.view', ['id' => \$r['id']]) ?>\"><?= $linkLabel ?></a></td>"];
foreach ($listed as $f) {
    $heads[] = '<th><?= $h->t(' . $q($f->label()) . ') ?></th>';
    $cells[] = '<td><?= ' . FieldCode::listCell($f, $statusEnum) . ' ?></td>';
}
$statusSelect = $m->hasStatus() ? <<<'PHP'

        <select name="status" aria-label="<?= $h->t('Status') ?>">
            <option value=""><?= $h->t('Any status') ?></option>
            <?php foreach (__ENUM__::cases() as $s): ?>
                <option value="<?= $h->e($s->value) ?>"<?= ($filters['status'] ?? '') === $s->value ? ' selected' : '' ?>><?= $h->t($s->label()) ?></option>
            <?php endforeach ?>
        </select>
PHP : '';
$statusSelect = str_replace('__ENUM__', $statusEnum, $statusSelect);

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

/**
 * Web · {{label}} of my organisation.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var array<string,string> $query
 */

$this->setTitle($h->tr('{{label}}'));
$hx = 'hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"';
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('{{label}}') ?></h1></div>
    <div class="page-head__actions">
        <?php if ($can('{{code}}.create')): ?>
            <a class="btn btn--primary" href="<?= $h->url('web.{{code}}.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New {{entityLabelLower}}') ?></a>
        <?php endif ?>
    </div>
</div>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('web.{{code}}') ?>" hx-get="<?= $h->url('web.{{code}}') ?>" <?= $hx ?> hx-trigger="submit, change from:select">
        <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Search…') ?>" aria-label="<?= $h->t('Search') ?>">__STATUS_SELECT__
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Search') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('folder') ?><p><?= $h->t('No {{labelLower}} found.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr>__HEADS__</tr></thead>
            <tbody>
            <?php foreach ($page->items as $r): ?>
                <tr>__CELLS__</tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'web.{{code}}', 'query' => $query]) ?>
</div>

PHP, ['__STATUS_SELECT__' => $statusSelect, '__HEADS__' => implode('', $heads), '__CELLS__' => implode('', $cells)]);
