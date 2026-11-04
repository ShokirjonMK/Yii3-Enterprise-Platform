<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $workers
 * @var string $filter
 */

$this->setTitle($h->tr('Workers'));
$this->setParameter('breadcrumbs', [$h->tr('Jobs & Scheduler') => $h->url('admin.jobs'), $h->tr('Workers')]);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Workers') ?></h1>
        <p class="page-head__sub"><?= $h->t('Queue workers and Kafka consumers heartbeat every 15 s; silent for more than 90 s = lost (the reaper re-queues their jobs).') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.jobs.workers']) ?>

<div class="card" id="workers-live" hx-get="<?= $h->url('admin.jobs.workers', [], $filter === 'all' ? [] : ['liveness' => $filter]) ?>" hx-trigger="every 15s" hx-select="#workers-live" hx-target="this" hx-swap="outerHTML">
    <div class="toolbar" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
        <?php foreach (['all' => 'All', 'alive' => 'Alive', 'lost' => 'Lost', 'stopped' => 'Stopped'] as $k => $label): ?>
            <a class="btn btn--sm<?= $filter === $k ? ' btn--primary' : '' ?>" href="<?= $h->url('admin.jobs.workers', [], $k === 'all' ? [] : ['liveness' => $k]) ?>"><?= $h->t($label) ?></a>
        <?php endforeach ?>
    </div>
    <?php if ($workers === []): ?>
        <div class="empty"><?= $h->icon('server') ?><div><?= $h->t('No workers registered. Start one with ./yii queue:work') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Worker') ?></th><th><?= $h->t('Type') ?></th><th><?= $h->t('Queues / group') ?></th><th><?= $h->t('Liveness') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('Heartbeat') ?></th><th><?= $h->t('Current job') ?></th><th class="num"><?= $h->t('Processed') ?></th><th class="num"><?= $h->t('Failed') ?></th><th class="num"><?= $h->t('Memory') ?></th><th><?= $h->t('Started') ?></th></tr></thead>
        <tbody>
        <?php foreach ($workers as $w): ?>
            <tr>
                <td class="mono small"><?= $h->e($w['id']) ?><div class="muted"><?= $h->e($w['hostname']) ?> · pid <?= (int) $w['pid'] ?></div></td>
                <td><?= $h->e($w['type']) ?></td>
                <td><?php foreach (array_filter(explode(',', (string) $w['queues'])) as $q): ?><span class="tag"><?= $h->e($q) ?></span><?php endforeach ?></td>
                <td><?= $h->badge($w['liveness'] === 'alive' ? 'healthy' : (string) $w['liveness'], $h->tr(ucfirst((string) $w['liveness']))) ?></td>
                <td><?= $h->badge((string) $w['status']) ?></td>
                <td class="nowrap small"><?= $h->ago($w['heartbeat_at']) ?></td>
                <td class="small"><?php if ($w['current_job_id']): ?><a class="mono" href="<?= $h->url('admin.jobs.view', ['id' => $w['current_job_id']]) ?>"><?= $h->e($w['job_type'] ?? substr((string) $w['current_job_id'], 0, 8)) ?></a><?php else: ?>—<?php endif ?></td>
                <td class="num"><?= (int) $w['jobs_processed'] ?></td>
                <td class="num"><?= (int) $w['jobs_failed'] ?></td>
                <td class="num small"><?= $h->bytes($w['memory_bytes']) ?></td>
                <td class="nowrap small"><?= $h->ago($w['started_at']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</div>
