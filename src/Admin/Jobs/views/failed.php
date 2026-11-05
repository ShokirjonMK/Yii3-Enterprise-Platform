<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array{queue:string,status:string,type:string,q:string} $filters
 * @var string $sort
 * @var list<string> $queues
 * @var list<string> $types
 */

$this->setTitle($h->tr('Failed & dead jobs'));
$this->setParameter('breadcrumbs', [$h->tr('Jobs & Scheduler') => $h->url('admin.jobs'), $h->tr('Failed & dead')]);
$manage = $can('jobs.manage');
$query = array_filter($filters + ['sort' => $sort], static fn($v) => $v !== '');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Failed & dead jobs') ?></h1>
        <p class="page-head__sub"><?= $h->t('Dead jobs exhausted their attempts (or failed permanently). Retrying resets the attempt counter.') ?></p>
    </div>
    <?php if ($manage && $page->total > 0): ?>
    <div class="page-head__actions">
        <form method="post" action="<?= $h->url('admin.jobs.retry_bulk') ?>" data-confirm="<?= $h->t('Re-queue all {n} matching jobs (max 500)?', ['n' => $page->total]) ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <input type="hidden" name="scope" value="filter">
            <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?= $h->e($k) ?>" value="<?= $h->e($v) ?>"><?php endforeach ?>
            <button class="btn" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Retry all matching') ?></button>
        </form>
    </div>
    <?php endif ?>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.jobs.failed']) ?>

<div class="card">
    <?= $this->render('./_filters', ['filters' => $filters, 'queues' => $queues, 'types' => $types, 'statuses' => ['failed', 'dead', 'cancelled'], 'route' => 'admin.jobs.failed', 'sort' => $sort]) ?>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('check') ?><div><?= $h->t('No failed or dead jobs.') ?></div></div>
    <?php else: ?>
        <?php if ($manage): ?>
        <form id="bulk-retry" method="post" action="<?= $h->url('admin.jobs.retry_bulk') ?>" data-confirm="<?= $h->t('Re-queue the selected jobs?') ?>" class="toolbar">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <input type="hidden" name="scope" value="selected">
            <button class="btn btn--sm btn--primary" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Retry selected') ?></button>
        </form>
        <?php endif ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr>
                <?php if ($manage): ?><th><span class="sr-only"><?= $h->t('Select') ?></span></th><?php endif ?>
                <th><?= $h->t('Job') ?></th><th><?= $h->t('Type') ?></th><th><?= $h->t('Queue') ?></th><th><?= $h->t('Status') ?></th>
                <th class="num"><?= $h->t('Attempts') ?></th><th class="num"><?= $h->t('Priority') ?></th>
                <th><?= $h->sortLink($h->tr('Created'), 'created_at', $page->sort, $query, 'admin.jobs.failed') ?></th>
                <th><?= $h->sortLink($h->tr('Finished'), 'finished_at', $page->sort, $query, 'admin.jobs.failed') ?></th>
                <th><?= $h->t('Last error') ?></th>
            </tr></thead>
            <tbody><?= $this->render('./_rows', ['items' => $page->items, 'selectable' => $manage]) ?></tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.jobs.failed', 'query' => $query]) ?>
</div>
