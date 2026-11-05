<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var list<array<string,mixed>> $schedules
 * @var list<array<string,mixed>> $runs
 */

use App\Shared\Infrastructure\Database\Sql;

$this->setTitle($h->tr('Schedules'));
$this->setParameter('breadcrumbs', [$h->tr('Jobs & Scheduler') => $h->url('admin.jobs'), $h->tr('Schedules')]);
$manage = $can('jobs.manage');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Schedules') ?></h1>
        <p class="page-head__sub"><?= $h->t('Cron schedules enqueue jobs exactly once per tick (leader election + unique run record).') ?></p>
    </div>
    <?php if ($manage): ?>
    <div class="page-head__actions"><a class="btn btn--primary" href="<?= $h->url('admin.jobs.schedules.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New schedule') ?></a></div>
    <?php endif ?>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.jobs.schedules']) ?>

<div class="card">
    <?php if ($schedules === []): ?>
        <div class="empty"><?= $h->icon('clock') ?><div><?= $h->t('No schedules defined.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Code') ?></th><th><?= $h->t('Cron') ?></th><th><?= $h->t('Job type') ?></th><th><?= $h->t('Queue') ?></th><th><?= $h->t('Enabled') ?></th><th><?= $h->t('Last run') ?></th><th><?= $h->t('Next run') ?></th><?php if ($manage): ?><th></th><?php endif ?></tr></thead>
        <tbody>
        <?php foreach ($schedules as $s): $enabled = Sql::bool($s['enabled']); ?>
            <tr>
                <td><strong><?= $h->e($s['name']) ?></strong><div class="mono small muted"><?= $h->e($s['code']) ?><?= Sql::bool($s['is_system']) ? ' · ' . $h->t('system') : '' ?></div></td>
                <td><code><?= $h->e($s['cron_expression']) ?></code><div class="small muted"><?= $h->e($s['timezone']) ?> · <?= $h->t('overlap: {o}', ['o' => $s['overlap']]) ?></div></td>
                <td><code><?= $h->e($s['job_type']) ?></code></td>
                <td><span class="tag"><?= $h->e($s['queue']) ?></span></td>
                <td><?= $h->badge($enabled ? 'enabled' : 'disabled', $h->tr($enabled ? 'Enabled' : 'Disabled')) ?></td>
                <td class="small nowrap"><?= $h->ago($s['last_run_at']) ?> <?= $s['last_status'] ? $h->badge((string) $s['last_status']) : '' ?>
                    <?php if ($s['last_job_id']): ?><div><a class="mono" href="<?= $h->url('admin.jobs.view', ['id' => $s['last_job_id']]) ?>"><?= $h->t('job') ?> <?= $h->e(substr((string) $s['last_job_id'], 0, 8)) ?></a> <?= $s['last_job_status'] ? $h->badge((string) $s['last_job_status']) : '' ?></div><?php endif ?>
                    <?php if ($s['last_error']): ?><div class="runtime-error"><?= $h->e(mb_strimwidth((string) $s['last_error'], 0, 120, '…')) ?></div><?php endif ?>
                </td>
                <td class="small nowrap"><?= $enabled ? $h->datetime($s['next_run_at'], 'Y-m-d H:i') : '—' ?></td>
                <?php if ($manage): ?>
                <td class="actions">
                    <div class="btn-group">
                        <a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.jobs.schedules.edit', ['id' => $s['id']]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
                        <form method="post" action="<?= $h->url('admin.jobs.schedules.run', ['id' => $s['id']]) ?>" data-confirm="<?= $h->t('Run {code} now?', ['code' => $s['code']]) ?>">
                            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                            <button class="btn btn--sm" type="submit"><?= $h->icon('play') ?> <?= $h->t('Run now') ?></button>
                        </form>
                        <form method="post" action="<?= $h->url('admin.jobs.schedules.toggle', ['id' => $s['id']]) ?>">
                            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                            <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                            <button class="btn btn--sm" type="submit"><?= $h->icon($enabled ? 'pause' : 'check') ?> <?= $h->t($enabled ? 'Disable' : 'Enable') ?></button>
                        </form>
                    </div>
                </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Recent runs') ?></h2></div>
    <?= $this->render('./_runs', ['runs' => $runs]) ?>
</div>
