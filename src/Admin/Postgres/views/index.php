<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var array<string,mixed> $overview
 * @var list<array{state:string,count:int}> $states
 * @var list<array<string,mixed>> $long
 * @var list<array<string,mixed>> $blocking
 * @var list<array<string,mixed>> $replicas
 * @var array{installed:bool,reason:?string} $statements
 */

$this->setTitle($h->tr('PostgreSQL Management'));
$this->setParameter('breadcrumbs', [$h->tr('PostgreSQL Management')]);
$uptime = (int) $overview['uptime_seconds'];
$uptimeText = sprintf('%dd %02dh %02dm', intdiv($uptime, 86400), intdiv($uptime % 86400, 3600), intdiv($uptime % 3600, 60));
$connPct = (int) $overview['max_connections'] > 0 ? (int) round((int) $overview['connections'] / (int) $overview['max_connections'] * 100) : 0;
$manage = $can('db.manage');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('PostgreSQL Management') ?></h1>
        <p class="page-head__sub"><?= $h->e($overview['version']) ?> · <?= $h->e($overview['database']) ?> · <?= $h->t($overview['in_recovery'] ? 'replica (read-only)' : 'primary') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.postgres']) ?>

<div id="pg-live" hx-get="<?= $h->url('admin.postgres') ?>" hx-trigger="every 30s" hx-select="#pg-live" hx-target="this" hx-swap="outerHTML">
<div class="grid grid--4">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('clock') ?> <?= $h->t('Uptime') ?></div><div class="kpi__value"><?= $h->e($uptimeText) ?></div><div class="kpi__meta"><?= $h->t('since {t}', ['t' => $h->datetime($overview['started_at'])]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('database') ?> <?= $h->t('Database size') ?></div><div class="kpi__value"><?= $h->e($h->bytes($overview['size_bytes'])) ?></div><div class="kpi__meta"><?= $h->t('shared_buffers {v}', ['v' => $overview['shared_buffers']]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('users') ?> <?= $h->t('Connections') ?></div><div class="kpi__value"><?= (int) $overview['connections'] ?> / <?= (int) $overview['max_connections'] ?></div>
        <div class="bar<?= $connPct > 85 ? ' bar--bad' : ($connPct > 70 ? ' bar--warn' : ' bar--ok') ?>"><span class="w-<?= min(100, (int) (round($connPct / 5) * 5)) ?>"></span></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('activity') ?> <?= $h->t('Cache hit ratio') ?></div><div class="kpi__value"><?= $overview['cache_hit_pct'] === null ? '—' : $h->e($overview['cache_hit_pct']) . '%' ?></div><div class="kpi__meta"><?= $h->t('{d} deadlocks · {r} rollbacks', ['d' => $overview['deadlocks'] ?? 0, 'r' => $overview['xact_rollback'] ?? 0]) ?></div></div>
</div>

<div class="grid grid--sidebar mt">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Long-running queries (> 30 s)') ?></h2><a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.postgres.activity') ?>"><?= $h->t('All activity') ?></a></div>
            <?php if ($long === []): ?>
                <div class="empty"><?= $h->icon('check') ?><div><?= $h->t('No long-running statements.') ?></div></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th>PID</th><th><?= $h->t('User / app') ?></th><th class="num"><?= $h->t('Duration') ?></th><th><?= $h->t('Wait') ?></th><th><?= $h->t('Query (redacted)') ?></th><?php if ($manage): ?><th></th><?php endif ?></tr></thead>
                <tbody>
                <?php foreach ($long as $r): ?>
                    <tr>
                        <td class="num"><?= (int) $r['pid'] ?></td>
                        <td class="small"><?= $h->e($r['usename']) ?><div class="muted"><?= $h->e($r['application_name']) ?></div></td>
                        <td class="num"><?= $h->e($r['duration_seconds']) ?> s</td>
                        <td class="small"><?= $h->e(trim(($r['wait_event_type'] ?? '') . ' ' . ($r['wait_event'] ?? ''))) ?: '—' ?></td>
                        <td><code class="runtime-sql"><?= $h->e($r['query']) ?></code></td>
                        <?php if ($manage): ?><td class="actions"><?= $r['cancellable'] ? $this->render('./_cancel', ['pid' => (int) $r['pid']]) : '' ?></td><?php endif ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Blocked sessions') ?></h2></div>
            <?php if ($blocking === []): ?>
                <div class="empty"><?= $h->t('No lock waits.') ?></div>
            <?php else: ?>
                <?= $this->render('./_blocking', ['blocking' => $blocking]) ?>
            <?php endif ?>
        </div>
    </div>
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Connections by state') ?></h2></div>
            <table class="table table--compact"><tbody>
                <?php foreach ($states as $s): ?><tr><td><?= $h->e($s['state']) ?></td><td class="num"><?= (int) $s['count'] ?></td></tr><?php endforeach ?>
            </tbody></table>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Read replicas') ?></h2></div>
            <?php if ($replicas === []): ?>
                <div class="card__body small"><?= $h->badge('not_configured', $h->tr('Not configured')) ?> <span class="muted"><?= $h->t('DB_REPLICAS is empty; all reads go to the primary.') ?></span></div>
            <?php else: ?>
            <table class="table table--compact"><tbody>
                <?php foreach ($replicas as $rep): ?>
                    <tr><td>#<?= (int) $rep['index'] ?></td><td><?= $h->badge($rep['healthy'] ? 'healthy' : 'down', $h->tr($rep['healthy'] ? 'Healthy' : 'Down')) ?></td>
                        <td class="num small"><?= $rep['lagSeconds'] === null ? '—' : $h->e(round($rep['lagSeconds'], 2)) . ' s' ?></td></tr>
                    <?php if ($rep['error']): ?><tr><td colspan="3" class="small runtime-error"><?= $h->e($rep['error']) ?></td></tr><?php endif ?>
                <?php endforeach ?>
            </tbody></table>
            <?php endif ?>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Server') ?></h2></div>
            <div class="card__body"><dl class="dl runtime-dl">
                <dt><?= $h->t('Version') ?></dt><dd><?= $h->e($overview['server_version']) ?></dd>
                <dt><?= $h->t('Role') ?></dt><dd class="mono small"><?= $h->e($overview['role']) ?></dd>
                <dt>wal_level</dt><dd><?= $h->e($overview['wal_level']) ?></dd>
                <dt>archive_mode</dt><dd><?= $h->e($overview['archive_mode']) ?></dd>
                <dt>statement_timeout</dt><dd><?= $h->e($overview['statement_timeout']) ?></dd>
                <dt>pg_stat_statements</dt><dd><?= $statements['installed'] ? $h->badge('enabled', $h->tr('Installed')) : $h->badge('not_configured', $h->tr('Not installed')) ?></dd>
                <dt><?= $h->t('Temp bytes') ?></dt><dd><?= $h->e($h->bytes($overview['temp_bytes'] ?? 0)) ?></dd>
            </dl></div>
        </div>
    </div>
</div>
<p class="small muted"><?= $h->t('Updated {time}', ['time' => date('H:i:s')]) ?></p>
</div>
