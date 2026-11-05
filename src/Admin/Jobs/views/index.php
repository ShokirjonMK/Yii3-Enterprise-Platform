<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var array<string, array{pending:int,running:int,failed:int,dead:int,paused:bool}> $stats
 * @var array<string,int> $totals
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array{queue:string,status:string,type:string,q:string} $filters
 * @var string $sort
 * @var list<string> $queues
 * @var list<string> $types
 */

$this->setTitle($h->tr('Jobs & Scheduler'));
$this->setParameter('breadcrumbs', [$h->tr('Jobs & Scheduler')]);
$manage = $can('jobs.manage');
$query = array_filter($filters + ['sort' => $sort], static fn($v) => $v !== '');
$selfUrl = $h->url('admin.jobs', [], $query + ($page->page > 1 ? ['page' => $page->page] : []));
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Jobs & Scheduler') ?></h1>
        <p class="page-head__sub"><?= $h->t('Durable PostgreSQL queue (SKIP LOCKED), workers and cron schedules.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.jobs']) ?>

<div class="grid grid--4">
    <?php foreach ([['pending', 'clock'], ['running', 'play'], ['failed', 'alert'], ['dead', 'x']] as [$status, $icon]): ?>
        <a class="card kpi" href="<?= $h->url('admin.jobs', [], ['status' => $status]) ?>">
            <div class="kpi__label"><?= $h->icon($icon) ?> <?= $h->t(ucfirst($status)) ?></div>
            <div class="kpi__value"><?= number_format($totals[$status] ?? 0, 0, '.', ' ') ?></div>
            <div class="kpi__meta"><?= $h->t('{n} succeeded in 24 h', ['n' => $totals['succeeded'] ?? 0]) ?></div>
        </a>
    <?php endforeach ?>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Queues') ?></h2></div>
    <?php if ($stats === []): ?>
        <div class="empty"><?= $h->icon('clock') ?><div><?= $h->t('No queues yet — jobs have not been enqueued.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Queue') ?></th><th class="num"><?= $h->t('Pending') ?></th><th class="num"><?= $h->t('Running') ?></th><th class="num"><?= $h->t('Failed') ?></th><th class="num"><?= $h->t('Dead') ?></th><th><?= $h->t('State') ?></th><?php if ($manage): ?><th></th><?php endif ?></tr></thead>
        <tbody>
        <?php foreach ($stats as $queue => $s): ?>
            <tr>
                <td><a href="<?= $h->url('admin.jobs', [], ['queue' => $queue]) ?>"><span class="tag"><?= $h->e($queue) ?></span></a></td>
                <td class="num"><?= (int) $s['pending'] ?></td>
                <td class="num"><?= (int) $s['running'] ?></td>
                <td class="num"><?= (int) $s['failed'] ?></td>
                <td class="num"><?= (int) $s['dead'] ?></td>
                <td><?= $s['paused'] ? $h->badge('paused') : $h->badge('active') ?></td>
                <?php if ($manage): ?>
                <td class="actions">
                    <form method="post" action="<?= $h->url('admin.jobs.queue.pause', ['queue' => $queue]) ?>"<?= $s['paused'] ? '' : ' data-confirm="' . $h->t('Pause queue {q}? Workers stop taking new jobs from it.', ['q' => $queue]) . '"' ?>>
                        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                        <input type="hidden" name="paused" value="<?= $s['paused'] ? '0' : '1' ?>">
                        <button class="btn btn--sm" type="submit"><?= $h->icon($s['paused'] ? 'play' : 'pause') ?> <?= $h->t($s['paused'] ? 'Resume' : 'Pause') ?></button>
                    </form>
                </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Jobs') ?></h2><span class="small muted"><?= $h->t('auto-refresh every 10 s') ?></span></div>
    <?= $this->render('./_filters', ['filters' => $filters, 'queues' => $queues, 'types' => $types, 'statuses' => App\Admin\Jobs\JobsReadModel::STATUSES, 'route' => 'admin.jobs', 'sort' => $sort]) ?>
    <div id="jobs-live" hx-get="<?= $h->e($selfUrl) ?>" hx-trigger="every 10s" hx-select="#jobs-live" hx-target="this" hx-swap="outerHTML">
        <?php if ($page->items === []): ?>
            <div class="empty"><?= $h->icon('search') ?><div><?= $h->t('No jobs match the filters.') ?></div></div>
        <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr>
                <th><?= $h->t('Job') ?></th>
                <th><?= $h->sortLink($h->tr('Type'), 'type', $page->sort, $query, 'admin.jobs') ?></th>
                <th><?= $h->sortLink($h->tr('Queue'), 'queue', $page->sort, $query, 'admin.jobs') ?></th>
                <th><?= $h->sortLink($h->tr('Status'), 'status', $page->sort, $query, 'admin.jobs') ?></th>
                <th class="num"><?= $h->sortLink($h->tr('Attempts'), 'attempts', $page->sort, $query, 'admin.jobs') ?></th>
                <th class="num"><?= $h->sortLink($h->tr('Priority'), 'priority', $page->sort, $query, 'admin.jobs') ?></th>
                <th><?= $h->sortLink($h->tr('Created'), 'created_at', $page->sort, $query, 'admin.jobs') ?></th>
                <th><?= $h->sortLink($h->tr('Next / finished'), 'available_at', $page->sort, $query, 'admin.jobs') ?></th>
                <th><?= $h->t('Last error') ?></th>
            </tr></thead>
            <tbody><?= $this->render('./_rows', ['items' => $page->items]) ?></tbody>
        </table></div>
        <?php endif ?>
        <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.jobs', 'query' => $query]) ?>
        <p class="small muted card__body"><?= $h->t('Updated {time}', ['time' => date('H:i:s')]) ?></p>
    </div>
</div>
