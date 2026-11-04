<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $workers
 * @var array<string,int> $summary
 * @var array<string,mixed> $runtime
 * @var array<string, array{status:string, latency_ms:float, details:array}> $checks
 */

$this->setTitle($h->tr('Infrastructure'));
$this->setParameter('breadcrumbs', [$h->tr('Infrastructure')]);
$op = $runtime['opcache'];
$alive = array_sum($summary);
$bool = static fn($v) => $v === true || $v === 't' || $v === 1;
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Infrastructure') ?></h1>
        <p class="page-head__sub"><?= $h->t('Runtime inventory from worker heartbeats, dependency health, PHP runtime and Kubernetes workloads.') ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('admin.infrastructure') ?>" hx-get="<?= $h->url('admin.infrastructure') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML"><?= $h->icon('refresh') ?> <?= $h->t('Refresh') ?></a>
    </div>
</div>

<div class="grid grid--4">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('server') ?> <?= $h->t('Live processes') ?></div><div class="kpi__value"><?= (int) $alive ?></div>
        <div class="kpi__meta"><?php foreach ($summary as $type => $n): ?><span class="tag"><?= $h->e($type) ?>: <?= (int) $n ?></span><?php endforeach ?><?= $summary === [] ? $h->t('no heartbeats recorded') : '' ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('code') ?> PHP</div><div class="kpi__value"><?= $h->e($runtime['php_version']) ?></div><div class="kpi__meta"><?= $h->e($runtime['sapi']) ?> · <?= $h->e($runtime['hostname']) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('bolt') ?> OPcache</div>
        <div class="kpi__value"><?= $op === null ? $h->t('off') : $h->e($op['hit_rate']) . '%' ?></div>
        <div class="kpi__meta"><?= $op === null ? $h->t('opcache_get_status() unavailable') : $h->t('hit rate · {n} scripts', ['n' => $op['cached_scripts']]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('activity') ?> <?= $h->t('Load average') ?></div><div class="kpi__value"><?= $runtime['load'] ? $h->e(number_format((float) $runtime['load'][0], 2)) : '—' ?></div>
        <div class="kpi__meta"><?= $runtime['load'] ? $h->e(number_format((float) $runtime['load'][1], 2) . ' · ' . number_format((float) $runtime['load'][2], 2)) : '' ?></div></div>
</div>

<div class="grid grid--sidebar mt">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Worker processes') ?></h2><span class="small muted"><?= $h->t('from the workers heartbeat table (last 24 h)') ?></span></div>
            <?php if ($workers === []): ?>
                <div class="empty"><?= $h->icon('server') ?><p><?= $h->t('No worker has registered a heartbeat yet.') ?></p></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th><?= $h->t('Process') ?></th><th><?= $h->t('Type') ?></th><th><?= $h->t('Queues') ?></th><th><?= $h->t('Status') ?></th><th class="num"><?= $h->t('Jobs') ?></th><th class="num"><?= $h->t('Failed') ?></th><th class="num"><?= $h->t('Memory') ?></th><th><?= $h->t('Heartbeat') ?></th></tr></thead>
                <tbody>
                <?php foreach ($workers as $w): $isAlive = $bool($w['alive']); ?>
                    <tr>
                        <td class="small"><span class="dot dot--<?= $isAlive ? 'ok' : 'muted' ?>"></span> <span class="mono"><?= $h->e($w['hostname']) ?>:<?= (int) $w['pid'] ?></span></td>
                        <td><span class="tag"><?= $h->e($w['type']) ?></span></td>
                        <td class="small"><?= $h->e($w['queues']) ?></td>
                        <td><?= $h->badge($isAlive ? (string) $w['status'] : 'lost', $isAlive ? null : $h->tr('no heartbeat')) ?></td>
                        <td class="num"><?= number_format((int) $w['jobs_processed'], 0, '.', ' ') ?></td>
                        <td class="num"><?= number_format((int) $w['jobs_failed'], 0, '.', ' ') ?></td>
                        <td class="num small"><?= $h->e($h->bytes($w['memory_bytes'])) ?></td>
                        <td class="small nowrap"><?= $h->ago($w['heartbeat_at']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </div>

        <div class="card" id="k8s" hx-get="<?= $h->url('admin.infrastructure.k8s') ?>" hx-trigger="load" hx-swap="innerHTML">
            <div class="card__head"><h2><?= $h->t('Kubernetes') ?></h2></div>
            <div class="card__body muted"><?= $h->t('Loading cluster state…') ?></div>
        </div>

        <div class="card">
            <div class="card__head"><h2><?= $h->t('PHP runtime') ?></h2><span class="small muted"><?= $h->t('process serving this request') ?></span></div>
            <div class="card__body grid grid--2">
                <dl class="dl">
                    <dt><?= $h->t('Version') ?></dt><dd><?= $h->e($runtime['php_version']) ?> (<?= $h->e($runtime['sapi']) ?>)</dd>
                    <dt><?= $h->t('OS') ?></dt><dd><?= $h->e($runtime['os']) ?></dd>
                    <dt>memory_limit</dt><dd class="mono"><?= $h->e($runtime['memory_limit']) ?></dd>
                    <dt>max_execution_time</dt><dd class="mono"><?= $h->e($runtime['max_execution_time']) ?></dd>
                    <dt>upload / post max</dt><dd class="mono"><?= $h->e($runtime['upload_max_filesize'] . ' / ' . $runtime['post_max_size']) ?></dd>
                    <dt>expose_php</dt><dd><?= $runtime['expose_php'] ? $h->badge('warning', 'On') : $h->badge('ok', 'Off') ?></dd>
                    <dt>display_errors</dt><dd class="mono"><?= $h->e($runtime['display_errors'] ?: 'Off') ?></dd>
                    <dt>APCu</dt><dd><?= $runtime['apcu'] ? $h->badge('enabled') : $h->badge('disabled') ?></dd>
                    <dt><?= $h->t('Memory (current / peak)') ?></dt><dd><?= $h->e($h->bytes($runtime['memory_usage']) . ' / ' . $h->bytes($runtime['memory_peak'])) ?></dd>
                </dl>
                <?php if ($op !== null): ?>
                <dl class="dl">
                    <dt>OPcache</dt><dd><?= $op['enabled'] ? $h->badge('enabled') : $h->badge('disabled') ?><?= $op['jit'] ? ' <span class="tag">JIT</span>' : '' ?></dd>
                    <dt><?= $h->t('Memory') ?></dt><dd><?= $h->e($h->bytes($op['used_memory']) . ' / ' . $h->bytes($op['total_memory'])) ?>
                        <div class="bar <?= $op['memory_pct'] > 90 ? 'bar--bad' : ($op['memory_pct'] > 75 ? 'bar--warn' : 'bar--ok') ?>"><span class="w-<?= (int) (round($op['memory_pct'] / 5) * 5) ?>"></span></div></dd>
                    <dt><?= $h->t('Wasted') ?></dt><dd><?= $h->e($op['wasted_pct']) ?>%</dd>
                    <dt><?= $h->t('Scripts / keys') ?></dt><dd><?= (int) $op['cached_scripts'] ?> / <?= (int) $op['max_cached_keys'] ?></dd>
                    <dt><?= $h->t('Hits / misses') ?></dt><dd><?= number_format($op['hits'], 0, '.', ' ') ?> / <?= number_format($op['misses'], 0, '.', ' ') ?></dd>
                    <dt><?= $h->t('Restarts') ?></dt><dd><?= (int) $op['restarts'] ?></dd>
                    <dt>validate_timestamps</dt><dd><?= $op['validate_timestamps'] ? $h->badge('warning', 'On') : $h->badge('ok', 'Off') ?></dd>
                    <dt><?= $h->t('Started') ?></dt><dd><?= $h->datetime($op['start_time']) ?></dd>
                </dl>
                <?php endif ?>
            </div>
            <details class="card__body">
                <summary class="small"><?= $h->t('{n} loaded extensions', ['n' => count($runtime['extensions'])]) ?></summary>
                <p><?php foreach ($runtime['extensions'] as $ext): ?><span class="tag"><?= $h->e($ext) ?></span><?php endforeach ?></p>
            </details>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Component health') ?></h2></div>
        <?= $this->render('//admin/dashboard/_health', ['checks' => $checks]) ?>
    </div>
</div>
