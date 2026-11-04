<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var list<array<string,mixed>> $tables
 * @var list<array<string,mixed>> $indexes
 * @var list<array<string,mixed>> $unused
 * @var list<array<string,mixed>> $bloat
 * @var list<string> $maintainable
 * @var string $order
 */

$this->setTitle($h->tr('Tables & indexes'));
$this->setParameter('breadcrumbs', [$h->tr('PostgreSQL Management') => $h->url('admin.postgres'), $h->tr('Tables & indexes')]);
$manage = $can('db.manage');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Tables & indexes') ?></h1>
        <p class="page-head__sub"><?= $h->t('Sizes, dead tuples (bloat estimate), index usage. Maintenance is limited to ANALYZE and VACUUM (ANALYZE).') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.postgres.storage']) ?>

<?php if ($manage): ?>
<form class="card mb" method="post" action="<?= $h->url('admin.postgres.maintain') ?>" data-confirm="<?= $h->t('Run the selected maintenance operation? Requires re-authentication.') ?>">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="toolbar">
        <strong><?= $h->t('Maintenance') ?></strong>
        <select name="table" aria-label="<?= $h->t('Table') ?>" required>
            <option value=""><?= $h->t('Select table…') ?></option>
            <?php foreach ($maintainable as $t): ?><option value="<?= $h->e($t) ?>"><?= $h->e($t) ?></option><?php endforeach ?>
        </select>
        <select name="operation" aria-label="<?= $h->t('Operation') ?>">
            <option value="analyze">ANALYZE</option>
            <option value="vacuum">VACUUM (ANALYZE)</option>
        </select>
        <button class="btn btn--sm btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Run') ?></button>
        <span class="small muted"><?= $h->t('Non-blocking; VACUUM FULL, REINDEX, DROP and TRUNCATE are intentionally unavailable.') ?></span>
    </div>
</form>
<?php endif ?>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Tables') ?></h2>
        <span class="btn-group" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
            <?php foreach (['size' => 'Size', 'dead' => 'Dead tuples', 'seq' => 'Seq scans', 'name' => 'Name'] as $k => $label): ?>
                <a class="btn btn--sm<?= $order === $k ? ' btn--primary' : ' btn--ghost' ?>" href="<?= $h->url('admin.postgres.storage', [], ['order' => $k]) ?>"><?= $h->t($label) ?></a>
            <?php endforeach ?>
        </span>
    </div>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Table') ?></th><th class="num"><?= $h->t('Total') ?></th><th class="num"><?= $h->t('Data') ?></th><th class="num"><?= $h->t('Indexes') ?></th><th class="num"><?= $h->t('Live rows') ?></th><th class="num"><?= $h->t('Dead') ?></th><th class="num"><?= $h->t('Seq / idx scans') ?></th><th><?= $h->t('Last (auto)vacuum') ?></th><th><?= $h->t('Last (auto)analyze') ?></th></tr></thead>
        <tbody>
        <?php foreach ($tables as $t): ?>
            <tr>
                <td class="mono small"><?= $h->e($t['schemaname'] . '.' . $t['relname']) ?></td>
                <td class="num small"><?= $h->e($h->bytes($t['total_bytes'])) ?></td>
                <td class="num small"><?= $h->e($h->bytes($t['table_bytes'])) ?></td>
                <td class="num small"><?= $h->e($h->bytes($t['index_bytes'])) ?></td>
                <td class="num"><?= number_format((int) $t['n_live_tup'], 0, '.', ' ') ?></td>
                <td class="num"><?= number_format((int) $t['n_dead_tup'], 0, '.', ' ') ?> <span class="small muted">(<?= $h->e($t['dead_pct']) ?>%)</span></td>
                <td class="num small"><?= (int) $t['seq_scan'] ?> / <?= (int) $t['idx_scan'] ?></td>
                <td class="small nowrap"><?= $h->ago(max((string) $t['last_vacuum'], (string) $t['last_autovacuum']) ?: null) ?></td>
                <td class="small nowrap"><?= $h->ago(max((string) $t['last_analyze'], (string) $t['last_autoanalyze']) ?: null) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>

<div class="grid grid--2 mt">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Bloat estimate (dead tuples)') ?></h2></div>
        <?php if ($bloat === []): ?><div class="empty"><?= $h->t('No dead tuples.') ?></div><?php else: ?>
        <table class="table table--compact"><thead><tr><th><?= $h->t('Table') ?></th><th class="num"><?= $h->t('Dead') ?></th><th class="num">%</th><th class="num"><?= $h->t('≈ wasted') ?></th></tr></thead><tbody>
            <?php foreach ($bloat as $b): ?><tr><td class="mono small"><?= $h->e($b['schemaname'] . '.' . $b['relname']) ?></td><td class="num"><?= number_format((int) $b['n_dead_tup'], 0, '.', ' ') ?></td><td class="num"><?= $h->e($b['dead_pct']) ?></td><td class="num small"><?= $h->e($h->bytes($b['wasted_bytes_estimate'])) ?></td></tr><?php endforeach ?>
        </tbody></table>
        <?php endif ?>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Unused indexes') ?></h2><span class="small muted"><?= $h->t('idx_scan = 0, non-unique') ?></span></div>
        <?php if ($unused === []): ?><div class="empty"><?= $h->t('Every index has been used.') ?></div><?php else: ?>
        <table class="table table--compact"><thead><tr><th><?= $h->t('Index') ?></th><th><?= $h->t('Table') ?></th><th class="num"><?= $h->t('Size') ?></th></tr></thead><tbody>
            <?php foreach ($unused as $u): ?><tr><td class="mono small" title="<?= $h->e($u['definition']) ?>"><?= $h->e($u['indexrelname']) ?></td><td class="mono small"><?= $h->e($u['relname']) ?></td><td class="num small"><?= $h->e($h->bytes($u['size_bytes'])) ?></td></tr><?php endforeach ?>
        </tbody></table>
        <p class="card__body small muted"><?= $h->t('Counters reset with pg_stat_reset() and differ per replica — verify on all nodes before dropping (through a migration).') ?></p>
        <?php endif ?>
    </div>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Largest indexes') ?></h2></div>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Index') ?></th><th><?= $h->t('Table') ?></th><th class="num"><?= $h->t('Size') ?></th><th class="num"><?= $h->t('Scans') ?></th><th class="num"><?= $h->t('Tuples read') ?></th><th><?= $h->t('Kind') ?></th></tr></thead>
        <tbody>
        <?php foreach ($indexes as $i): ?>
            <tr><td class="mono small"><?= $h->e($i['indexrelname']) ?></td><td class="mono small"><?= $h->e($i['relname']) ?></td><td class="num small"><?= $h->e($h->bytes($i['size_bytes'])) ?></td><td class="num"><?= number_format((int) $i['idx_scan'], 0, '.', ' ') ?></td><td class="num"><?= number_format((int) $i['idx_tup_read'], 0, '.', ' ') ?></td>
                <td class="small"><?= App\Shared\Infrastructure\Database\Sql::bool($i['is_primary']) ? 'PK' : (App\Shared\Infrastructure\Database\Sql::bool($i['is_unique']) ? 'UNIQUE' : '') ?></td></tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>
