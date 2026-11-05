<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,mixed> $summary
 * @var array<string,mixed> $wal
 * @var list<array<string,mixed>> $jobs
 * @var list<array<string,mixed>> $schedules
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Backup & Recovery'));
$this->setParameter('breadcrumbs', [$h->tr('Backup & Recovery')]);
$query = ['sort' => $page->sort];
$bool = static fn($v) => $v === true || $v === 't' || $v === 1;
$hasRunning = (int) ($summary['running'] ?? 0) > 0 || array_filter($jobs, static fn($j) => in_array($j['status'], ['pending', 'running'], true)) !== [];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Backup & Recovery') ?></h1>
        <p class="page-head__sub"><?= $h->t('Logical pg_dump backups to S3 with checksums, retention and automated restore verification; WAL archiving for point-in-time recovery.') ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($can('backup.manage')): ?>
            <form method="post" action="<?= $h->url('admin.backups.start') ?>" data-confirm="<?= $h->t('Start a full logical backup now?') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <button class="btn btn--primary" type="submit"<?= $summary['storage_configured'] ? '' : ' disabled' ?>><?= $h->icon('archive') ?> <?= $h->t('Start backup') ?></button>
            </form>
        <?php endif ?>
    </div>
</div>

<?php if (!$summary['storage_configured']): ?>
    <div class="alert alert--warning"><?= $h->t('Object storage is not configured (S3_ENDPOINT / S3_KEY / S3_SECRET): backups cannot be stored.') ?></div>
<?php endif ?>

<div class="grid grid--4">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('check') ?> <?= $h->t('Last successful backup') ?></div><div class="kpi__value small-value"><?= $h->ago($summary['last_success']) ?></div><div class="kpi__meta"><?= $h->datetime($summary['last_success']) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('shield') ?> <?= $h->t('Last passed restore test') ?></div><div class="kpi__value"><?= $h->ago($summary['last_verified']) ?></div><div class="kpi__meta"><?= $h->datetime($summary['last_verified']) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('box') ?> <?= $h->t('Available backups') ?></div><div class="kpi__value"><?= (int) $summary['available'] ?></div><div class="kpi__meta"><?= $h->e($h->bytes($summary['total_bytes'])) ?> · <?= $h->t('retention {d} days', ['d' => $summary['retention_days']]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('globe') ?> <?= $h->t('Off-site copy') ?></div><div class="kpi__value"><?= $summary['offsite_bucket'] !== '' ? $h->t('On') : $h->t('Off') ?></div><div class="kpi__meta mono"><?= $summary['offsite_bucket'] !== '' ? $h->e('s3://' . $summary['offsite_bucket']) : $h->t('ops.backup_offsite_bucket not set') ?></div></div>
</div>

<div class="grid grid--sidebar mt">
    <div class="stack">
        <div class="card"<?= $hasRunning ? ' hx-get="' . $h->url('admin.backups') . '" hx-trigger="every 10s" hx-target="#main" hx-select="#main" hx-swap="outerHTML"' : '' ?>>
            <div class="card__head"><h2><?= $h->t('Backups') ?></h2><span class="small muted mono">s3://<?= $h->e($summary['bucket']) ?>/backups/</span></div>
            <?php if ($page->items === []): ?>
                <div class="empty"><?= $h->icon('archive') ?><p><?= $h->t('No backups yet.') ?></p></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr>
                    <th><?= $h->sortLink($h->tr('Started'), 'started_at', $page->sort, $query, 'admin.backups') ?></th>
                    <th><?= $h->sortLink($h->tr('Status'), 'status', $page->sort, $query, 'admin.backups') ?></th>
                    <th class="num"><?= $h->sortLink($h->tr('Size'), 'size', $page->sort, $query, 'admin.backups') ?></th>
                    <th><?= $h->sortLink($h->tr('Restore test'), 'verified_at', $page->sort, $query, 'admin.backups') ?></th>
                    <th><?= $h->t('Initiated by') ?></th><th><?= $h->t('Expires') ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($page->items as $b): ?>
                    <tr data-backup="<?= $h->e($b['id']) ?>">
                        <td class="nowrap"><a href="<?= $h->url('admin.backups.view', ['id' => $b['id']]) ?>"><?= $h->datetime($b['started_at']) ?></a><br><span class="small muted"><?= $h->e($b['type']) ?> · <?= $h->e($b['database_name'] ?? '') ?></span></td>
                        <td><?= $h->badge($b['status']) ?><?= $b['offsite_location'] ? ' <span class="tag">off-site</span>' : '' ?></td>
                        <td class="num small"><?= $h->e($h->bytes($b['size_bytes'])) ?></td>
                        <td><?= $b['verify_status'] ? $h->badge($b['verify_status']) : '—' ?><br><span class="small muted"><?= $h->ago($b['verified_at']) ?></span></td>
                        <td class="small"><?= $h->e($b['initiator'] ?? 'scheduler') ?></td>
                        <td class="small nowrap"><?= $h->datetime($b['expires_at'], 'Y-m-d') ?></td>
                        <td class="actions">
                            <?php if ($can('backup.manage') && $b['status'] === 'succeeded'): ?>
                                <a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.backups.download', ['id' => $b['id']]) ?>"><?= $h->icon('download') ?></a>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
            <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.backups', 'query' => $query]) ?>
        </div>

        <div class="card">
            <div class="card__head"><h2><?= $h->t('Recent backup jobs') ?></h2></div>
            <?php if ($jobs === []): ?><div class="empty small"><?= $h->t('No backup jobs yet.') ?></div><?php else: ?>
            <table class="table table--compact"><tbody>
            <?php foreach ($jobs as $j): ?>
                <tr><td class="mono small"><?= $h->e($j['type']) ?></td><td><?= $h->badge($j['status']) ?><?= $j['status'] === 'running' ? ' ' . (int) $j['progress'] . '%' : '' ?></td>
                    <td class="small"><?= $h->ago($j['created_at']) ?></td><td class="small muted"><?= $h->e(mb_strimwidth((string) $j['last_error'], 0, 120, '…')) ?></td></tr>
            <?php endforeach ?>
            </tbody></table>
            <?php endif ?>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('WAL archiving (PITR)') ?></h2><?= $wal['healthy'] ? $h->badge('healthy') : $h->badge('degraded', $h->tr('attention')) ?></div>
            <div class="card__body">
                <dl class="dl ops-dl-tight">
                    <dt>archive_mode</dt><dd class="mono"><?= $h->e($wal['archive_mode'] ?? '—') ?></dd>
                    <dt>wal_level</dt><dd class="mono"><?= $h->e($wal['wal_level'] ?? '—') ?></dd>
                    <dt><?= $h->t('Archived') ?></dt><dd><?= number_format((int) ($wal['archived_count'] ?? 0), 0, '.', ' ') ?></dd>
                    <dt><?= $h->t('Last archived') ?></dt><dd class="mono small"><?= $h->e($wal['last_archived_wal'] ?? '—') ?><br><?= $h->ago($wal['last_archived_time'] ?? null) ?></dd>
                    <dt><?= $h->t('Failures') ?></dt><dd><?= (int) ($wal['failed_count'] ?? 0) ?><?php if (!empty($wal['last_failed_wal'])): ?><br><span class="small mono"><?= $h->e($wal['last_failed_wal']) ?> · <?= $h->ago($wal['last_failed_time']) ?></span><?php endif ?></dd>
                    <dt><?= $h->t('Current WAL') ?></dt><dd class="mono small"><?= $h->e($wal['current_wal'] ?? ($bool($wal['in_recovery'] ?? false) ? 'replica' : '—')) ?></dd>
                    <dt>archive_timeout</dt><dd class="mono"><?= $h->e($wal['archive_timeout'] ?? '—') ?></dd>
                </dl>
                <p class="small muted mt"><?= $h->t('Point-in-time recovery = base backup + every WAL segment since. The logical dumps listed here restore to the moment of the dump only; for PITR restore a base backup and replay archived WAL to a target time (see docs/BACKUP_RECOVERY.md).') ?></p>
            </div>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Schedules') ?></h2></div>
            <table class="table table--compact"><tbody>
            <?php foreach ($schedules as $s): ?>
                <tr><td class="mono small"><?= $h->e($s['code']) ?><br><span class="muted"><?= $h->e($s['cron_expression']) ?></span></td>
                    <td class="right"><?= $bool($s['enabled']) ? $h->badge('enabled') : $h->badge('disabled') ?><br><span class="small muted"><?= $h->t('next') ?> <?= $h->datetime($s['next_run_at']) ?></span></td></tr>
            <?php endforeach ?>
            </tbody></table>
            <div class="card__foot small"><?php if ($h->urlOrNull('admin.jobs.schedules')): ?><a href="<?= $h->url('admin.jobs.schedules') ?>"><?= $h->t('Manage schedules') ?></a><?php endif ?></div>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Runbooks') ?></h2></div>
            <ul class="card__body small">
                <li><span class="mono">docs/BACKUP_RECOVERY.md</span> — <?= $h->t('backup design, RPO/RTO, restore & PITR') ?></li>
                <li><span class="mono">docs/runbooks/restore-database.md</span> — <?= $h->t('restore a logical backup') ?></li>
                <li><span class="mono">docs/runbooks/pitr-recovery.md</span> — <?= $h->t('point-in-time recovery') ?></li>
                <li><span class="mono">docs/OPERATIONS.md</span> — <?= $h->t('disaster recovery drill') ?></li>
            </ul>
        </div>
    </div>
</div>
