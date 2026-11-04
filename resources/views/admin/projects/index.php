<?php

declare(strict_types=1);

/**
 * Admin · Projects list (search / filter / sort / pagination via HTMX partial navigation).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var callable(string):bool $can
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $filters
 * @var array $query
 * @var array<string,int> $counts
 * @var list<array> $reports
 * @var list<App\Modules\Projects\Domain\ProjectStatus> $statuses
 * @var list<string> $priorities
 */

$this->setTitle($h->tr('Projects'));
$this->setParameter('breadcrumbs', [$h->tr('Business modules') => $h->urlOrNull('admin.modules'), $h->tr('Projects')]);
$hx = 'hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"';
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Projects') ?></h1>
        <p class="page-head__sub"><?= $h->t('Reference business module: lifecycle, RBAC, tenant isolation, audit and Kafka events.') ?></p>
    </div>
    <div class="page-head__actions">
        <form method="post" action="<?= $h->url('admin.projects.report.request') ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <input type="hidden" name="status" value="<?= $h->e($filters['status']) ?>">
            <input type="hidden" name="priority" value="<?= $h->e($filters['priority']) ?>">
            <input type="hidden" name="q" value="<?= $h->e($filters['q']) ?>">
            <button class="btn" type="submit"><?= $h->icon('download') ?> <?= $h->t('Generate report') ?></button>
        </form>
        <?php if ($can('projects.create')): ?>
            <a class="btn btn--primary" href="<?= $h->url('admin.projects.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New project') ?></a>
        <?php endif ?>
    </div>
</div>

<div class="grid grid--4 mb">
    <?php foreach (['draft', 'active', 'on_hold', 'completed'] as $s): ?>
        <div class="card kpi">
            <div class="kpi__label"><?= $h->badge($s, $h->tr(App\Modules\Projects\Domain\ProjectStatus::from($s)->label())) ?></div>
            <div class="kpi__value"><?= (int) ($counts[$s] ?? 0) ?></div>
        </div>
    <?php endforeach ?>
</div>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.projects') ?>" hx-get="<?= $h->url('admin.projects') ?>" <?= $hx ?> hx-trigger="submit, change from:select, search from:input[type=search]">
        <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Search code, name, description…') ?>" aria-label="<?= $h->t('Search') ?>">
        <select name="status" aria-label="<?= $h->t('Status') ?>">
            <option value=""><?= $h->t('All open statuses') ?></option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= $h->e($s->value) ?>"<?= $filters['status'] === $s->value ? ' selected' : '' ?>><?= $h->t($s->label()) ?></option>
            <?php endforeach ?>
        </select>
        <select name="priority" aria-label="<?= $h->t('Priority') ?>">
            <option value=""><?= $h->t('Any priority') ?></option>
            <?php foreach ($priorities as $p): ?>
                <option value="<?= $h->e($p) ?>"<?= $filters['priority'] === $p ? ' selected' : '' ?>><?= $h->t(ucfirst($p)) ?></option>
            <?php endforeach ?>
        </select>
        <label class="row small"><input type="checkbox" name="include_archived" value="1"<?= $filters['include_archived'] === '1' ? ' checked' : '' ?>> <?= $h->t('Include archived') ?></label>
        <label class="row small"><input type="checkbox" name="mine" value="1"<?= ($query['mine'] ?? '') === '1' ? ' checked' : '' ?>> <?= $h->t('Only mine') ?></label>
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>

    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('folder') ?><p><?= $h->t('No projects match the filters.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap" id="projects-table"><table class="table">
            <thead><tr>
                <th><?= $h->sortLink($h->tr('Code'), 'code', $page->sort, $query, 'admin.projects') ?></th>
                <th><?= $h->sortLink($h->tr('Name'), 'name', $page->sort, $query, 'admin.projects') ?></th>
                <th><?= $h->sortLink($h->tr('Status'), 'status', $page->sort, $query, 'admin.projects') ?></th>
                <th><?= $h->sortLink($h->tr('Priority'), 'priority', $page->sort, $query, 'admin.projects') ?></th>
                <th><?= $h->t('Owner') ?></th>
                <th class="right"><?= $h->sortLink($h->tr('Budget'), 'budget', $page->sort, $query, 'admin.projects') ?></th>
                <th><?= $h->sortLink($h->tr('Due'), 'due_on', $page->sort, $query, 'admin.projects') ?></th>
                <th><?= $h->sortLink($h->tr('Updated'), 'updated_at', $page->sort, $query, 'admin.projects') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $p): ?>
                <tr>
                    <td class="nowrap"><a class="mono" href="<?= $h->url('admin.projects.view', ['id' => $p['id']]) ?>" hx-get="<?= $h->url('admin.projects.view', ['id' => $p['id']]) ?>" <?= $hx ?>><?= $h->e($p['code']) ?></a></td>
                    <td><?= $h->e($p['name']) ?><?php foreach ((array) $p['tags'] as $t): ?> <span class="tag"><?= $h->e($t) ?></span><?php endforeach ?></td>
                    <td><?= $h->badge($p['status'], $h->tr(App\Modules\Projects\Domain\ProjectStatus::from((string) $p['status'])->label())) ?></td>
                    <td><span class="project-priority project-priority--<?= $h->e($p['priority']) ?>"><?= $h->t(ucfirst((string) $p['priority'])) ?></span></td>
                    <td><?= $h->e($p['owner_name'] ?? '') ?></td>
                    <td class="right nowrap mono"><?= $p['budget'] === null ? '—' : $h->e(number_format((float) $p['budget'], 2, '.', ' ')) ?></td>
                    <td class="nowrap"><?= $h->e($p['due_on'] ?? '—') ?></td>
                    <td class="nowrap small muted"><?= $h->ago($p['updated_at']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.projects', 'query' => $query]) ?>
</div>

<?php if ($reports !== []): ?>
<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Recent reports') ?></h2></div>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Requested') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('Rows') ?></th><th><?= $h->t('Size') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($reports as $r): ?>
            <tr>
                <td class="small"><?= $h->ago($r['created_at']) ?></td>
                <td><?= $h->badge($r['status'] === 'done' ? 'completed' : $r['status'], $h->tr($r['status'])) ?></td>
                <td><?= $r['rows_count'] === null ? '—' : (int) $r['rows_count'] ?></td>
                <td><?= $r['size_bytes'] === null ? '—' : $h->bytes($r['size_bytes']) ?></td>
                <td class="right"><a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.projects.report', ['id' => $r['id']]) ?>"><?= $h->t('Open') ?></a></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>
<?php endif ?>
