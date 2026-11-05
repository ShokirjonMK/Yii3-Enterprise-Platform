<?php

declare(strict_types=1);

/**
 * Template: admin list view (src/Modules/<Module>/Admin/views/index.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$statusEnum = '\\App\\Modules\\' . $m->module . '\\Domain\\' . $m->entity . 'Status';
$rules = '\\App\\Modules\\' . $m->module . '\\Domain\\' . $m->entity . 'Rules';
$display = $m->displayField();
$listed = array_values(array_filter($m->fields, static fn($f) => $f->isListed() && $f !== $display));
$listed = array_slice($listed, 0, 7);

$heads = [];
$cells = [];
$linkLabel = $display !== null ? "\$h->e((string) (\$r[" . $q($display->name) . "] ?? ''))" : "\$h->e(substr((string) \$r['id'], 0, 8))";
$heads[] = $display !== null
    ? "                <th><?= \$h->sortLink(\$h->tr(" . $q($display->label()) . "), " . $q($display->name) . ", \$page->sort, \$query, 'admin.{{code}}') ?></th>"
    : "                <th><?= \$h->t('Id') ?></th>";
$cells[] = "                    <td><a href=\"<?= \$h->url('admin.{{code}}.view', ['id' => \$r['id']]) ?>\" hx-get=\"<?= \$h->url('admin.{{code}}.view', ['id' => \$r['id']]) ?>\" <?= \$hx ?>><?= $linkLabel ?></a></td>";
foreach ($listed as $f) {
    $label = "\$h->tr(" . $q($f->label()) . ')';
    $heads[] = $f->isSortable()
        ? "                <th><?= \$h->sortLink($label, " . $q($f->name) . ", \$page->sort, \$query, 'admin.{{code}}') ?></th>"
        : "                <th><?= \$h->t(" . $q($f->label()) . ") ?></th>";
    $cells[] = '                    <td' . ($f->type === 'decimal' || $f->type === 'int' ? ' class="right nowrap mono"' : '') . '><?= ' . FieldCode::listCell($f, $statusEnum) . ' ?></td>';
}
$heads[] = "                <th><?= \$h->sortLink(\$h->tr('Updated'), 'updated_at', \$page->sort, \$query, 'admin.{{code}}') ?></th>";
$cells[] = "                    <td class=\"nowrap small muted\"><?= \$h->ago(\$r['updated_at']) ?></td>";

$filters = [];
foreach ($m->filterable() as $f) {
    $k = $q($f->name);
    if ($f->type === 'enum') {
        $values = $f->isStatus() ? "$statusEnum::values()" : "$rules::{$f->constName()}_OPTIONS";
        $filters[] = "        <select name=$k aria-label=\"<?= \$h->t(" . $q($f->label()) . ") ?>\">\n            <option value=\"\"><?= \$h->t(" . $q('Any ' . mb_strtolower($f->label())) . ") ?></option>\n            <?php foreach ($values as \$o): ?>\n                <option value=\"<?= \$h->e(\$o) ?>\"<?= (\$filters[$k] ?? '') === \$o ? ' selected' : '' ?>><?= \$h->t(ucfirst(str_replace('_', ' ', \$o))) ?></option>\n            <?php endforeach ?>\n        </select>";
    } elseif ($f->type === 'bool') {
        $filters[] = "        <select name=$k aria-label=\"<?= \$h->t(" . $q($f->label()) . ") ?>\">\n            <option value=\"\"><?= \$h->t(" . $q($f->label()) . ") ?>: <?= \$h->t('any') ?></option>\n            <option value=\"1\"<?= (\$filters[$k] ?? '') === '1' ? ' selected' : '' ?>><?= \$h->t('Yes') ?></option>\n            <option value=\"0\"<?= (\$filters[$k] ?? '') === '0' ? ' selected' : '' ?>><?= \$h->t('No') ?></option>\n        </select>";
    } else {
        $filters[] = "        <input type=\"text\" name=$k value=\"<?= \$h->e(\$filters[$k] ?? '') ?>\" placeholder=\"<?= \$h->t(" . $q($f->label() . ' (user id)') . ") ?>\" aria-label=\"<?= \$h->t(" . $q($f->label()) . ") ?>\">";
    }
}
$search = $m->searchable() !== []
    ? "        <input type=\"search\" name=\"q\" value=\"<?= \$h->e(\$filters['q']) ?>\" placeholder=\"<?= \$h->t('Search…') ?>\" aria-label=\"<?= \$h->t('Search') ?>\">\n"
    : '';

$kpi = '';
if ($m->hasStatus()) {
    $kpi = <<<'PHP'

<div class="grid grid--4 mb">
    <?php foreach (__STATUS__::cases() as $s): ?>
        <div class="card kpi">
            <div class="kpi__label"><?= $h->badge($s->value, $h->tr($s->label())) ?></div>
            <div class="kpi__value"><?= (int) ($counts[$s->value] ?? 0) ?></div>
        </div>
    <?php endforeach ?>
</div>
PHP;
    $kpi = str_replace('__STATUS__', $statusEnum, $kpi);
}
$reportBtn = '';
$reportList = '';
if ($m->has('report')) {
    $hidden = '';
    foreach (array_merge(['q'], array_map(static fn($f) => $f->name, $m->filterable())) as $k) {
        $hidden .= "            <input type=\"hidden\" name=\"$k\" value=\"<?= \$h->e(\$filters['$k'] ?? '') ?>\">\n";
    }
    $reportBtn = "        <form method=\"post\" action=\"<?= \$h->url('admin.{{code}}.report.request') ?>\">\n            <input type=\"hidden\" name=\"_csrf\" value=\"<?= \$h->e(\$csrf) ?>\">\n$hidden            <button class=\"btn\" type=\"submit\"><?= \$h->icon('download') ?> <?= \$h->t('Generate report') ?></button>\n        </form>\n";
    $reportList = <<<'PHP'

<?php if ($reports !== []): ?>
<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Recent reports') ?></h2></div>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Requested') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('Rows') ?></th><th><?= $h->t('Size') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($reports as $rep): ?>
            <tr>
                <td class="small"><?= $h->ago($rep['created_at']) ?></td>
                <td><?= $h->badge($rep['status'] === 'done' ? 'completed' : (string) $rep['status'], $h->tr((string) $rep['status'])) ?></td>
                <td><?= $rep['rows_count'] === null ? '—' : (int) $rep['rows_count'] ?></td>
                <td><?= $rep['size_bytes'] === null ? '—' : $h->bytes($rep['size_bytes']) ?></td>
                <td class="right"><a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.{{code}}.report', ['id' => $rep['id']]) ?>"><?= $h->t('Open') ?></a></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>
<?php endif ?>
PHP;
}

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

/**
 * Admin · {{label}} list (search / filters / sort / pagination via HTMX partial navigation).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var callable(string):bool $can
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var array<string,string> $query
 * @var array<string,int> $counts
 * @var list<array<string,mixed>> $reports
 */

$counts ??= [];
$reports ??= [];
$this->setTitle($h->tr('{{label}}'));
$this->setParameter('breadcrumbs', [$h->tr('Business modules') => $h->urlOrNull('admin.modules'), $h->tr('{{label}}')]);
$hx = 'hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"';
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('{{label}}') ?></h1>
        <p class="page-head__sub"><?= $h->t('Generated business module: RBAC, tenant isolation, optimistic locking, audit.') ?></p>
    </div>
    <div class="page-head__actions">
__REPORT_BTN__        <?php if ($can('{{code}}.create')): ?>
            <a class="btn btn--primary" href="<?= $h->url('admin.{{code}}.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New {{entityLabelLower}}') ?></a>
        <?php endif ?>
    </div>
</div>
__KPI__
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.{{code}}') ?>" hx-get="<?= $h->url('admin.{{code}}') ?>" <?= $hx ?> hx-trigger="submit, change from:select, search from:input[type=search]">
__SEARCH____FILTERS__
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>

    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('folder') ?><p><?= $h->t('No {{labelLower}} match the filters.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap" id="{{slug}}-table"><table class="table">
            <thead><tr>
__HEADS__
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $r): ?>
                <tr>
__CELLS__
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.{{code}}', 'query' => $query]) ?>
</div>
__REPORT_LIST__
PHP, [
    '__REPORT_BTN__' => $reportBtn,
    '__KPI__' => $kpi,
    '__SEARCH__' => $search,
    '__FILTERS__' => implode("\n", $filters),
    '__HEADS__' => implode("\n", $heads),
    '__CELLS__' => implode("\n", $cells),
    '__REPORT_LIST__' => $reportList,
]);
