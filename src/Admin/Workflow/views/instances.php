<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $query
 * @var list<string> $workflows
 */
$this->setTitle($h->tr('Workflow instances'));
$this->setParameter('breadcrumbs', [$h->tr('Workflow & Approvals') => $h->url('admin.workflow'), $h->tr('Instances')]);
$sort = $page->sort;
$route = 'admin.workflow.instances';
?>
<?= $this->render('./_tabs', ['active' => 'instances']) ?>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url($route) ?>" hx-get="<?= $h->url($route) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="submit, change from:select">
        <select name="workflow" aria-label="<?= $h->t('Workflow') ?>"><option value=""><?= $h->t('All workflows') ?></option>
            <?php foreach ($workflows as $w): ?><option value="<?= $h->e($w) ?>"<?= ($query['workflow'] ?? '') === $w ? ' selected' : '' ?>><?= $h->e($w) ?></option><?php endforeach ?></select>
        <input type="text" name="state" value="<?= $h->e($query['state'] ?? '') ?>" placeholder="<?= $h->t('State') ?>" aria-label="<?= $h->t('State') ?>">
        <input type="search" name="q" value="<?= $h->e($query['q'] ?? '') ?>" placeholder="<?= $h->t('Entity id…') ?>" aria-label="<?= $h->t('Search') ?>">
        <button class="btn" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('flow') ?><div><?= $h->t('No instances match.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Workflow') ?></th><th><?= $h->sortLink($h->tr('Entity'), 'entity_id', $sort, $query, $route) ?></th>
            <th><?= $h->sortLink($h->tr('State'), 'state', $sort, $query, $route) ?></th><th><?= $h->t('Version') ?></th>
            <th><?= $h->sortLink($h->tr('Updated'), 'updated_at', $sort, $query, $route) ?></th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $i): ?>
            <tr>
                <td class="mono small"><?= $h->e($i['workflow_code']) ?></td>
                <td><a class="mono" href="<?= $h->url('admin.workflow.instance', ['id' => $i['id']]) ?>"><?= $h->e($i['entity_type'] . ':' . $i['entity_id']) ?></a></td>
                <td><span class="tag"><?= $h->e($i['state']) ?></span></td>
                <td class="small"><?= (int) $i['version'] ?></td>
                <td class="small nowrap"><?= $h->ago($i['updated_at']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => $route, 'query' => $query]) ?>
</div>
