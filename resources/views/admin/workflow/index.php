<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database\Sql;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $query
 * @var int $pendingForMe
 * @var callable(string):bool $can
 */
$this->setTitle($h->tr('Workflow & Approvals'));
$this->setParameter('breadcrumbs', [$h->tr('Workflow & Approvals')]);
$sort = $page->sort;
?>
<?= $this->render('./_tabs', ['active' => 'definitions']) ?>
<?php if ($pendingForMe > 0): ?>
    <div class="alert alert--info"><?= $h->icon('flow') ?> <a href="<?= $h->url('admin.workflow.approvals') ?>"><?= $h->t('{n} approval request(s) are waiting for your decision.', ['n' => $pendingForMe]) ?></a></div>
<?php endif ?>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.workflow') ?>" hx-get="<?= $h->url('admin.workflow') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
        <input type="search" name="q" value="<?= $h->e($query['q'] ?? '') ?>" placeholder="<?= $h->t('Search code, name, entity…') ?>" aria-label="<?= $h->t('Search') ?>">
        <button class="btn" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
        <span class="topbar__spacer"></span>
        <?php if ($can('workflow.manage')): ?><a class="btn btn--primary" href="<?= $h->url('admin.workflow.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New workflow') ?></a><?php endif ?>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('flow') ?><div><?= $h->t('No workflows defined.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('Code'), 'code', $sort, $query, 'admin.workflow') ?></th>
            <th><?= $h->sortLink($h->tr('Name'), 'name', $sort, $query, 'admin.workflow') ?></th>
            <th><?= $h->sortLink($h->tr('Entity type'), 'entity_type', $sort, $query, 'admin.workflow') ?></th>
            <th><?= $h->t('States') ?></th><th><?= $h->t('Enabled') ?></th><th class="num"><?= $h->t('Instances') ?></th><th><?= $h->t('Version') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $w): $d = Sql::json($w['definition']); ?>
            <tr>
                <td><a class="mono" href="<?= $h->url('admin.workflow.show', ['id' => $w['id']]) ?>"><?= $h->e($w['code']) ?></a></td>
                <td><?= $h->e($w['name']) ?></td>
                <td class="mono small"><?= $h->e($w['entity_type']) ?></td>
                <td class="small"><?php foreach ((array) ($d['states'] ?? []) as $s): ?><span class="tag"><?= $h->e(is_array($s) ? ($s['code'] ?? '') : $s) ?></span><?php endforeach ?></td>
                <td><?= Sql::bool($w['enabled']) ? $h->badge('enabled') : $h->badge('disabled') ?></td>
                <td class="num"><a href="<?= $h->url('admin.workflow.instances', [], ['workflow' => $w['code']]) ?>"><?= (int) $w['instances'] ?></a></td>
                <td class="small">v<?= (int) $w['version'] ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.workflow', 'query' => $query]) ?>
</div>
