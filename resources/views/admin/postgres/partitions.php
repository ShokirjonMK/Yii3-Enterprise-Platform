<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var list<array<string,mixed>> $partitions
 */

$this->setTitle($h->tr('Partitions'));
$this->setParameter('breadcrumbs', [$h->tr('PostgreSQL Management') => $h->url('admin.postgres'), $h->tr('Partitions')]);
$total = array_sum(array_map(static fn($p) => (int) $p['size_bytes'], $partitions));
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('audit_log partitions') ?></h1>
        <p class="page-head__sub"><?= $h->t('Monthly range partitions. The maintenance job creates the next 3 months and detaches (never drops) partitions older than the audit retention.') ?></p>
    </div>
    <?php if ($can('db.manage')): ?>
    <div class="page-head__actions">
        <form method="post" action="<?= $h->url('admin.postgres.partitions.run') ?>" data-confirm="<?= $h->t('Enqueue partition maintenance now? Requires re-authentication.') ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <button class="btn btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Run partition maintenance') ?></button>
        </form>
    </div>
    <?php endif ?>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.postgres.partitions']) ?>

<div class="card">
    <?php if ($partitions === []): ?>
        <div class="empty"><?= $h->t('audit_log has no partitions.') ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Partition') ?></th><th><?= $h->t('Bounds') ?></th><th class="num"><?= $h->t('Rows (estimate)') ?></th><th class="num"><?= $h->t('Size') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($partitions as $p): $current = str_ends_with((string) $p['name'], date('Ym')); ?>
            <tr>
                <td class="mono small"><?= $h->e($p['name']) ?></td>
                <td class="mono small"><?= $h->e($p['bounds']) ?></td>
                <td class="num"><?= number_format((int) $p['rows_estimate'], 0, '.', ' ') ?></td>
                <td class="num small"><?= $h->e($h->bytes($p['size_bytes'])) ?></td>
                <td><?= $current ? $h->badge('active', $h->tr('current month')) : ($p['name'] === 'audit_log_default' ? $h->badge('warning', $h->tr('default (should stay empty)')) : '') ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr><td colspan="3" class="right small muted"><?= $h->t('Total') ?></td><td class="num"><?= $h->e($h->bytes($total)) ?></td><td></td></tr></tfoot>
    </table></div>
    <?php endif ?>
</div>
