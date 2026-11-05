<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $partitions
 * @var int $retentionMonths
 * @var array|null $verification
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Audit integrity & partitions'));
$this->setParameter('breadcrumbs', [$h->tr('Audit & Compliance') => $h->url('admin.audit'), $h->tr('Integrity & partitions')]);
$totalBytes = array_sum(array_map(static fn($p) => (int) $p['bytes'], $partitions));
$totalRows = array_sum(array_map(static fn($p) => (int) $p['est_rows'], $partitions));
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Integrity & partitions') ?></h1>
        <p class="page-head__sub"><?= $h->t('Every audit row is HMAC-signed; UPDATE/DELETE are rejected by a trigger. Monthly partitions older than the retention period are detached (archived), never edited.') ?></p>
    </div>
</div>

<div class="grid grid--3">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('archive') ?> <?= $h->t('Retention') ?></div><div class="kpi__value"><?= (int) $retentionMonths ?></div><div class="kpi__meta"><?= $h->t('months (setting retention.audit_months)') ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('database') ?> <?= $h->t('Partitions') ?></div><div class="kpi__value"><?= count($partitions) ?></div><div class="kpi__meta"><?= $h->e($h->bytes($totalBytes)) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('file') ?> <?= $h->t('Rows (estimate)') ?></div><div class="kpi__value"><?= number_format($totalRows, 0, '.', ' ') ?></div><div class="kpi__meta"><?= $h->t('pg_class.reltuples') ?></div></div>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Integrity verification') ?></h2></div>
    <div class="card__body">
        <?php if ($verification !== null): ?>
            <?php if ($verification['invalid'] === []): ?>
                <div class="alert alert--success" data-verify-result="passed"><?= $h->t('Verified {n} records: all signatures are valid ({ms} ms).', ['n' => $verification['checked'], 'ms' => $verification['duration_ms']]) ?></div>
            <?php else: ?>
                <div class="alert alert--error" data-verify-result="failed"><?= $h->t('{bad} of {n} records have an INVALID signature — possible tampering. Escalate to the security officer.', ['bad' => count($verification['invalid']), 'n' => $verification['checked']]) ?></div>
                <p class="small"><?= $h->t('Record ids:') ?>
                    <?php foreach (array_slice($verification['invalid'], 0, 50) as $id): ?><a class="tag" href="<?= $h->url('admin.audit.view', ['id' => $id]) ?>"><?= (int) $id ?></a><?php endforeach ?>
                </p>
            <?php endif ?>
        <?php endif ?>
        <?php if ($can('audit.export')): ?>
            <form class="form form--2" method="post" action="<?= $h->url('admin.audit.verify') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <div class="field"><label for="v-from"><?= $h->t('From') ?></label><input id="v-from" type="date" name="from" value="<?= $h->e($verification['from'] ?? date('Y-m-d', strtotime('-30 days'))) ?>"></div>
                <div class="field"><label for="v-to"><?= $h->t('To (inclusive)') ?></label><input id="v-to" type="date" name="to" value="<?= $h->e($verification['to'] ?? '') ?>"></div>
                <div class="span-2"><button class="btn btn--primary" type="submit"><?= $h->icon('shield') ?> <?= $h->t('Verify signatures') ?></button> <span class="small muted"><?= $h->t('Up to 200 000 records per run; the run itself is audited.') ?></span></div>
            </form>
        <?php else: ?>
            <p class="muted"><?= $h->t('You need the audit.export permission to run a verification.') ?></p>
        <?php endif ?>
    </div>
</div>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Partitions') ?></h2></div>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Partition') ?></th><th><?= $h->t('Range') ?></th><th class="num"><?= $h->t('Rows (est.)') ?></th><th class="num"><?= $h->t('Size') ?></th><th><?= $h->t('State') ?></th></tr></thead>
        <tbody>
        <?php foreach ($partitions as $p): $attached = $p['attached'] === true || $p['attached'] === 't'; ?>
            <tr>
                <td class="mono"><?= $h->e($p['name']) ?></td>
                <td class="small mono"><?= $h->e($p['bounds'] ?? '—') ?></td>
                <td class="num"><?= number_format((int) $p['est_rows'], 0, '.', ' ') ?></td>
                <td class="num"><?= $h->e($h->bytes($p['bytes'])) ?></td>
                <td><?= $attached ? $h->badge('active', $h->tr('Attached')) : $h->badge('paused', $h->tr('Detached (archive)')) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <div class="card__foot small muted"><?= $h->t('Partitions are created ahead and detached after the retention period by the scheduled job platform.maintenance.partitions. Export detached partitions with pg_dump -t before dropping them (see docs/OPERATIONS.md).') ?></div>
</div>
