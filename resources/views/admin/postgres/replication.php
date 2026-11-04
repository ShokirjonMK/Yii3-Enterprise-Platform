<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{in_recovery:bool, standbys:list<array>, slots:list<array>, archiver:?array, replicas:list<array>, replica_count:int} $r
 * @var array<string,mixed> $overview
 */

use App\Shared\Infrastructure\Database\Sql;

$this->setTitle($h->tr('Replication & WAL'));
$this->setParameter('breadcrumbs', [$h->tr('PostgreSQL Management') => $h->url('admin.postgres'), $h->tr('Replication & WAL')]);
$arch = $r['archiver'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Replication & WAL') ?></h1>
        <p class="page-head__sub"><?= $h->t('Streaming standbys (pg_stat_replication), replication slots, WAL archiving (pg_stat_archiver) and application read replicas.') ?> <?= $h->t('HA operation (Patroni / CloudNativePG): see docs/DATABASE.md and docs/DEPLOYMENT.md.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.postgres.replication']) ?>

<div class="grid grid--4">
    <div class="card kpi"><div class="kpi__label"><?= $h->t('This node') ?></div><div class="kpi__value"><?= $h->t($r['in_recovery'] ? 'Replica' : 'Primary') ?></div><div class="kpi__meta">wal_level=<?= $h->e($overview['wal_level']) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->t('Streaming standbys') ?></div><div class="kpi__value"><?= count($r['standbys']) ?></div><div class="kpi__meta"><?= $h->t('connected to this node') ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->t('Replication slots') ?></div><div class="kpi__value"><?= count($r['slots']) ?></div><div class="kpi__meta"><?= $h->t('{n} inactive', ['n' => count(array_filter($r['slots'], static fn($s) => !Sql::bool($s['active'])))]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->t('WAL archiving') ?></div><div class="kpi__value"><?= $h->e($overview['archive_mode']) ?></div><div class="kpi__meta"><?= $arch === null ? '—' : $h->t('{a} archived · {f} failed', ['a' => $arch['archived_count'], 'f' => $arch['failed_count']]) ?></div></div>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Streaming standbys') ?></h2></div>
    <?php if ($r['standbys'] === []): ?>
        <div class="empty"><?= $h->t($r['in_recovery'] ? 'This node is a replica — query the primary for standby status.' : 'No standby is streaming from this node (single-node development setup).') ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Application') ?></th><th><?= $h->t('Client') ?></th><th><?= $h->t('State') ?></th><th><?= $h->t('Sync') ?></th><th><?= $h->t('Replay LSN') ?></th><th class="num"><?= $h->t('Lag bytes') ?></th><th class="num"><?= $h->t('Write / flush / replay lag') ?></th></tr></thead>
        <tbody>
        <?php foreach ($r['standbys'] as $s): ?>
            <tr><td class="mono small"><?= $h->e($s['application_name']) ?></td><td class="mono small"><?= $h->e($s['client_addr']) ?></td><td><?= $h->badge($s['state'] === 'streaming' ? 'ok' : 'warning', (string) $s['state']) ?></td><td><?= $h->e($s['sync_state']) ?></td><td class="mono small"><?= $h->e($s['replay_lsn']) ?></td><td class="num"><?= $h->e($h->bytes($s['replay_lag_bytes'])) ?></td>
                <td class="num small"><?= $h->e(($s['write_lag_s'] ?? '—') . ' / ' . ($s['flush_lag_s'] ?? '—') . ' / ' . ($s['replay_lag_s'] ?? '—')) ?> s</td></tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</div>

<div class="grid grid--2 mt">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Replication slots') ?></h2></div>
        <?php if ($r['slots'] === []): ?><div class="empty"><?= $h->t('No replication slots.') ?></div><?php else: ?>
        <table class="table table--compact"><thead><tr><th><?= $h->t('Slot') ?></th><th><?= $h->t('Type') ?></th><th><?= $h->t('Active') ?></th><th><?= $h->t('WAL status') ?></th><th class="num"><?= $h->t('Retained WAL') ?></th></tr></thead><tbody>
            <?php foreach ($r['slots'] as $s): ?><tr><td class="mono small"><?= $h->e($s['slot_name']) ?></td><td><?= $h->e($s['slot_type']) ?></td><td><?= Sql::bool($s['active']) ? $h->badge('active') : $h->badge('failed', $h->tr('inactive')) ?></td><td><?= $h->e($s['wal_status'] ?? '—') ?></td><td class="num"><?= $h->e($h->bytes($s['retained_bytes'])) ?></td></tr><?php endforeach ?>
        </tbody></table>
        <p class="card__body small muted"><?= $h->t('An inactive slot retains WAL forever and can fill the disk — drop it on the primary if the consumer is gone (operator task).') ?></p>
        <?php endif ?>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('WAL archiver') ?></h2></div>
        <?php if ($arch === null): ?><div class="empty">—</div><?php else: ?>
        <div class="card__body"><dl class="dl runtime-dl">
            <dt><?= $h->t('Archived') ?></dt><dd><?= (int) $arch['archived_count'] ?></dd>
            <dt><?= $h->t('Last archived WAL') ?></dt><dd class="mono small"><?= $h->e($arch['last_archived_wal'] ?? '—') ?></dd>
            <dt><?= $h->t('Last archived at') ?></dt><dd><?= $h->datetime($arch['last_archived_time'], 'Y-m-d H:i:s') ?></dd>
            <dt><?= $h->t('Failed') ?></dt><dd><?= (int) $arch['failed_count'] > 0 ? $h->badge('failed', (string) $arch['failed_count']) : '0' ?></dd>
            <dt><?= $h->t('Last failure') ?></dt><dd><?= $h->e($arch['last_failed_wal'] ?? '—') ?> <?= $h->datetime($arch['last_failed_time'], 'Y-m-d H:i:s') ?></dd>
        </dl>
        <?php if ($overview['archive_mode'] === 'off'): ?><p class="small muted"><?= $h->t('archive_mode=off: point-in-time recovery is not possible on this node. Production enables WAL archiving (pgBackRest / Barman / CloudNativePG barmanObjectStore).') ?></p><?php endif ?>
        </div>
        <?php endif ?>
    </div>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Application read replicas (DatabaseRouter)') ?></h2></div>
    <?php if ($r['replicas'] === []): ?>
        <div class="card__body"><?= $h->badge('not_configured', $h->tr('Not configured')) ?> <span class="small muted"><?= $h->t('Set DB_REPLICAS=host:port[,host:port] to route reads to replicas (writes and transactions stay on the primary; 5 s stickiness after writes).') ?></span></div>
    <?php else: ?>
    <table class="table table--compact"><thead><tr><th>#</th><th><?= $h->t('Health') ?></th><th class="num"><?= $h->t('Replay lag') ?></th><th><?= $h->t('Error') ?></th></tr></thead><tbody>
        <?php foreach ($r['replicas'] as $rep): ?><tr><td><?= (int) $rep['index'] ?></td><td><?= $h->badge($rep['healthy'] ? 'healthy' : 'down', $h->tr($rep['healthy'] ? 'Healthy' : 'Down')) ?></td><td class="num"><?= $rep['lagSeconds'] === null ? '—' : $h->e(round((float) $rep['lagSeconds'], 2)) . ' s' ?></td><td class="small runtime-error"><?= $h->e($rep['error'] ?? '') ?></td></tr><?php endforeach ?>
    </tbody></table>
    <?php endif ?>
</div>
