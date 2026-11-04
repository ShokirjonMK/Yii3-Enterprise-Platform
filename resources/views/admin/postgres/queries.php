<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{installed:bool,reason:?string} $status
 * @var list<array<string,mixed>> $rows
 * @var string $order
 */

$this->setTitle($h->tr('Slow queries'));
$this->setParameter('breadcrumbs', [$h->tr('PostgreSQL Management') => $h->url('admin.postgres'), $h->tr('Slow queries')]);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Slow queries') ?></h1>
        <p class="page-head__sub"><?= $h->t('From pg_stat_statements (normalised: constants are replaced by $n placeholders).') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.postgres.queries']) ?>

<?php if (!$status['installed']): ?>
    <div class="card"><div class="empty"><?= $h->icon('info') ?>
        <div><strong><?= $h->t('Not configured') ?></strong></div>
        <div class="small"><?= $h->e($status['reason']) ?></div>
        <div class="small"><?= $h->t('Enable with shared_preload_libraries = pg_stat_statements and CREATE EXTENSION pg_stat_statements; see docs/DATABASE.md.') ?></div>
    </div></div>
<?php else: ?>
<div class="card">
    <div class="toolbar" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
        <span class="small muted"><?= $h->t('Order by') ?></span>
        <?php foreach (['mean' => 'Mean time', 'total' => 'Total time', 'calls' => 'Calls', 'rows' => 'Rows'] as $k => $label): ?>
            <a class="btn btn--sm<?= $order === $k ? ' btn--primary' : '' ?>" href="<?= $h->url('admin.postgres.queries', [], ['order' => $k]) ?>"><?= $h->t($label) ?></a>
        <?php endforeach ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="empty"><?= $h->t('No statements recorded yet.') ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Query') ?></th><th class="num"><?= $h->t('Calls') ?></th><th class="num"><?= $h->t('Mean ms') ?></th><th class="num"><?= $h->t('Max ms') ?></th><th class="num"><?= $h->t('Total ms') ?></th><th class="num"><?= $h->t('Rows') ?></th><th class="num"><?= $h->t('Hit %') ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $blocks = (int) $r['shared_blks_hit'] + (int) $r['shared_blks_read']; ?>
            <tr>
                <td><code class="runtime-sql"><?= $h->e($r['query']) ?></code></td>
                <td class="num"><?= number_format((int) $r['calls'], 0, '.', ' ') ?></td>
                <td class="num"><?= $h->e($r['mean_ms']) ?></td>
                <td class="num"><?= $h->e($r['max_ms']) ?></td>
                <td class="num"><?= $h->e($r['total_ms']) ?></td>
                <td class="num"><?= number_format((int) $r['rows'], 0, '.', ' ') ?></td>
                <td class="num"><?= $blocks === 0 ? '—' : round((int) $r['shared_blks_hit'] / $blocks * 100, 1) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</div>
<?php endif ?>
