<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $b
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Backup {d}', ['d' => substr((string) $b['id'], 0, 8)]));
$this->setParameter('breadcrumbs', [$h->tr('Backup & Recovery') => $h->url('admin.backups'), substr((string) $b['id'], 0, 8)]);
$d = is_array($b['verify_details']) ? $b['verify_details'] : [];
$source = (array) ($d['source_counts'] ?? []);
$v = $d['verification'] ?? null;
$restored = (array) ($v['restored_counts'] ?? []);
$mismatch = (array) ($v['mismatches'] ?? []);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Backup') ?> <span class="mono"><?= $h->e(substr((string) $b['id'], 0, 8)) ?></span></h1>
        <p class="page-head__sub"><?= $h->badge($b['status']) ?> <?= $b['verify_status'] ? $h->badge($b['verify_status'], $h->tr('restore test: {s}', ['s' => $b['verify_status']])) : '' ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($can('backup.manage') && $b['status'] === 'succeeded'): ?>
            <form method="post" action="<?= $h->url('admin.backups.verify', ['id' => $b['id']]) ?>" data-confirm="<?= $h->t('Restore this backup into a temporary database and compare row counts?') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <button class="btn" type="submit"><?= $h->icon('shield') ?> <?= $h->t('Verify restore') ?></button>
            </form>
            <a class="btn" href="<?= $h->url('admin.backups.download', ['id' => $b['id']]) ?>"><?= $h->icon('download') ?> <?= $h->t('Download') ?></a>
        <?php endif ?>
    </div>
</div>

<div class="grid grid--sidebar">
    <div class="stack">
        <?php if ($b['error']): ?><div class="alert alert--error"><?= $h->e($b['error']) ?></div><?php endif ?>
        <?php if (is_array($v)): ?>
            <div class="alert <?= ($v['passed'] ?? false) ? 'alert--success' : 'alert--error' ?>" data-verify="<?= ($v['passed'] ?? false) ? 'passed' : 'failed' ?>">
                <?= ($v['passed'] ?? false)
                    ? $h->t('Restore verified into {db}: {n} tables match the backup snapshot ({ms} ms). Temporary database dropped.', ['db' => $v['database'], 'n' => $v['tables_compared'] ?? 0, 'ms' => $v['duration_ms'] ?? 0])
                    : $h->t('Restore verification failed: {e}', ['e' => $v['error'] ?? (count($mismatch) . ' table(s) differ')]) ?>
            </div>
        <?php endif ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Row counts') ?></h2><span class="small muted"><?= $h->t('snapshot at backup time vs. restored copy') ?></span></div>
            <?php if ($source === []): ?><div class="empty small"><?= $h->t('No snapshot recorded.') ?></div><?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th><?= $h->t('Table') ?></th><th class="num"><?= $h->t('Source') ?></th><th class="num"><?= $h->t('Restored') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($source as $table => $count): ?>
                    <tr><td class="mono"><?= $h->e($table) ?></td><td class="num"><?= number_format((int) $count, 0, '.', ' ') ?></td>
                        <td class="num"><?= array_key_exists($table, $restored) ? number_format((int) $restored[$table], 0, '.', ' ') : '—' ?></td>
                        <td><?= is_array($v) && $restored !== [] ? (isset($mismatch[$table]) ? $h->badge('failed', $h->tr('mismatch')) : $h->badge('passed', 'OK')) : '' ?></td></tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Details') ?></h2></div>
        <div class="card__body">
            <dl class="dl ops-dl-tight">
                <dt><?= $h->t('Type') ?></dt><dd><?= $h->e($b['type']) ?></dd>
                <dt><?= $h->t('Database') ?></dt><dd class="mono"><?= $h->e($b['database_name'] ?? '—') ?></dd>
                <dt><?= $h->t('Started') ?></dt><dd><?= $h->datetime($b['started_at'], 'Y-m-d H:i:s') ?></dd>
                <dt><?= $h->t('Finished') ?></dt><dd><?= $h->datetime($b['finished_at'], 'Y-m-d H:i:s') ?></dd>
                <dt><?= $h->t('Duration') ?></dt><dd><?= isset($d['duration_ms']) ? $h->e(round($d['duration_ms'] / 1000, 1)) . ' s' : '—' ?></dd>
                <dt><?= $h->t('Size') ?></dt><dd><?= $h->e($h->bytes($b['size_bytes'])) ?></dd>
                <dt>SHA-256</dt><dd class="mono small ops-break"><?= $h->e($b['checksum_sha256'] ?? '—') ?></dd>
                <dt><?= $h->t('Location') ?></dt><dd class="mono small ops-break"><?= $h->e($b['location'] ?? '—') ?></dd>
                <dt><?= $h->t('Off-site') ?></dt><dd class="mono small ops-break"><?= $h->e($b['offsite_location'] ?? ($d['offsite_error'] ?? '—')) ?></dd>
                <dt><?= $h->t('Expires') ?></dt><dd><?= $h->datetime($b['expires_at']) ?></dd>
                <dt><?= $h->t('Trigger') ?></dt><dd><?= $h->e($d['trigger'] ?? '—') ?></dd>
                <dt>pg_dump</dt><dd class="small"><?= $h->e($d['pg_dump'] ?? '—') ?></dd>
                <dt><?= $h->t('Verified') ?></dt><dd><?= $h->datetime($b['verified_at']) ?></dd>
            </dl>
        </div>
    </div>
</div>
