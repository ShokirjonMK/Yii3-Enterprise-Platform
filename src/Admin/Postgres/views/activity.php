<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var list<array<string,mixed>> $rows
 * @var list<array<string,mixed>> $blocking
 * @var list<array{mode:string,granted:bool,count:int}> $locks
 * @var bool $active
 * @var int $min
 */

$this->setTitle($h->tr('Activity & locks'));
$this->setParameter('breadcrumbs', [$h->tr('PostgreSQL Management') => $h->url('admin.postgres'), $h->tr('Activity & locks')]);
$manage = $can('db.manage');
$url = $h->url('admin.postgres.activity');
$query = ['active' => $active ? '1' : '0'] + ($min > 0 ? ['min' => $min] : []);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Activity & locks') ?></h1>
        <p class="page-head__sub"><?= $h->t('pg_stat_activity of this database. String literals in query text are masked; only queries of the application role can be cancelled.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.postgres.activity']) ?>

<div class="card" id="pg-activity" hx-get="<?= $h->e($h->url('admin.postgres.activity', [], $query)) ?>" hx-trigger="every 10s" hx-select="#pg-activity" hx-target="this" hx-swap="outerHTML">
    <form class="toolbar" method="get" action="<?= $h->e($url) ?>" hx-get="<?= $h->e($url) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change, submit">
        <select name="active" aria-label="<?= $h->t('Sessions') ?>">
            <option value="1"<?= $active ? ' selected' : '' ?>><?= $h->t('Non-idle sessions') ?></option>
            <option value="0"<?= $active ? '' : ' selected' ?>><?= $h->t('All sessions') ?></option>
        </select>
        <select name="min" aria-label="<?= $h->t('Minimum duration') ?>">
            <?php foreach ([0 => 'any duration', 5 => '≥ 5 s', 30 => '≥ 30 s', 300 => '≥ 5 min'] as $v => $label): ?><option value="<?= $v ?>"<?= $min === $v ? ' selected' : '' ?>><?= $h->t($label) ?></option><?php endforeach ?>
        </select>
        <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
        <span class="small muted"><?= $h->t('{n} sessions · updated {t}', ['n' => count($rows), 't' => date('H:i:s')]) ?></span>
    </form>
    <?php if ($rows === []): ?>
        <div class="empty"><?= $h->t('No sessions match.') ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th>PID</th><th><?= $h->t('User / app') ?></th><th><?= $h->t('Client') ?></th><th><?= $h->t('Type') ?></th><th><?= $h->t('State') ?></th><th><?= $h->t('Wait') ?></th><th class="num"><?= $h->t('Duration') ?></th><th><?= $h->t('Query (redacted)') ?></th><?php if ($manage): ?><th></th><?php endif ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="num"><?= (int) $r['pid'] ?><?= $r['is_self'] ? ' <span class="tag">' . $h->t('this page') . '</span>' : '' ?><?= (int) $r['blocked_by'] > 0 ? ' ' . $h->badge('blocked', $h->tr('blocked')) : '' ?></td>
                <td class="small"><?= $h->e($r['usename'] ?? '—') ?><div class="muted"><?= $h->e($r['application_name']) ?></div></td>
                <td class="small mono"><?= $h->e($r['client_addr'] ?? 'local') ?></td>
                <td class="small"><?= $h->e($r['backend_type']) ?></td>
                <td><?= $r['state'] ? $h->badge($r['state'] === 'active' ? 'running' : ($r['state'] === 'idle' ? 'idle' : 'warning'), (string) $r['state']) : '—' ?></td>
                <td class="small"><?= $h->e(trim(($r['wait_event_type'] ?? '') . ' ' . ($r['wait_event'] ?? ''))) ?: '—' ?></td>
                <td class="num small"><?= $r['duration_seconds'] === null ? '—' : $h->e($r['duration_seconds']) . ' s' ?></td>
                <td><code class="runtime-sql"><?= $h->e($r['query']) ?></code></td>
                <?php if ($manage): ?><td class="actions"><?= $r['cancellable'] && $r['state'] !== 'idle' ? $this->render('./_cancel', ['pid' => (int) $r['pid']]) : '' ?></td><?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</div>

<div class="grid grid--sidebar mt">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Blocking chains') ?></h2></div>
        <?= $blocking === [] ? '<div class="empty">' . $h->t('No lock waits.') . '</div>' : $this->render('./_blocking', ['blocking' => $blocking]) ?>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Locks by mode') ?></h2></div>
        <table class="table table--compact"><thead><tr><th><?= $h->t('Mode') ?></th><th><?= $h->t('Granted') ?></th><th class="num"><?= $h->t('Count') ?></th></tr></thead><tbody>
            <?php foreach ($locks as $l): ?><tr><td class="small mono"><?= $h->e($l['mode']) ?></td><td><?= $l['granted'] ? $h->badge('ok', $h->tr('yes')) : $h->badge('pending', $h->tr('waiting')) ?></td><td class="num"><?= (int) $l['count'] ?></td></tr><?php endforeach ?>
        </tbody></table>
    </div>
</div>
