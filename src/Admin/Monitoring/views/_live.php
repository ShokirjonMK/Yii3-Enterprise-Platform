<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $o
 */

$prom = $o['prometheus'];
$alerts = $o['alerts'];
$db = $o['db'];
?>
<div id="monitoring-live" hx-get="<?= $h->url('admin.monitoring.live') ?>" hx-trigger="every 30s" hx-swap="outerHTML">
    <?php if ($o['source'] === 'prometheus'): ?>
        <div class="alert alert--info" data-metrics-source="prometheus"><?= $h->t('Source: Prometheus at {url} — rates over 5 minutes across all scraped instances; sparklines show the last hour.', ['url' => (string) $prom['url']]) ?></div>
    <?php else: ?>
        <div class="alert alert--warning" data-metrics-source="local">
            <?= $prom['configured']
                ? $h->t('Source: in-app metrics registry (Prometheus at {url} is unreachable). Request counts and latency quantiles are cumulative since the last metrics reset, not rates. Start the monitoring profile for time series.', ['url' => (string) $prom['url']])
                : $h->t('Source: in-app metrics registry (PROMETHEUS_URL is not configured). Request counts and latency quantiles are cumulative since the last metrics reset, not rates.') ?>
        </div>
    <?php endif ?>

    <div class="grid grid--4">
        <?php foreach ($o['tiles'] as $t): ?>
            <div class="card kpi" data-tile="<?= $h->e($t['key']) ?>">
                <div class="kpi__label"><?= $h->t($t['label']) ?></div>
                <div class="kpi__value"><?= $h->e($t['value']) ?><?php if ($t['unit'] !== '' && $t['value'] !== '—'): ?> <span class="small muted"><?= $h->e($t['unit']) ?></span><?php endif ?></div>
                <div class="kpi__meta"><?= $h->t($t['hint']) ?></div>
                <?php if (is_array($t['series'])): ?><?= $this->render('./_sparkline', ['values' => $t['series'], 'label' => $h->tr($t['label'])]) ?><?php endif ?>
            </div>
        <?php endforeach ?>
    </div>

    <div class="grid grid--sidebar mt">
        <div class="stack">
            <div class="card">
                <div class="card__head"><h2><?= $h->t('Active alerts') ?></h2><span class="small muted mono"><?= $h->e((string) $alerts['url']) ?></span></div>
                <?php if (!$alerts['configured']): ?>
                    <div class="empty"><?= $h->t('Not configured (ALERTMANAGER_URL).') ?></div>
                <?php elseif (!$alerts['reachable']): ?>
                    <div class="empty" data-alerts="unreachable"><p><strong><?= $h->t('Alertmanager unreachable') ?></strong></p><p class="small"><?= $h->e((string) $alerts['error']) ?></p><p class="small"><?= $h->t('Start it with: docker compose --profile monitoring up -d') ?></p></div>
                <?php elseif ($alerts['items'] === []): ?>
                    <div class="empty"><?= $h->icon('check') ?><p><?= $h->t('No active alerts.') ?></p></div>
                <?php else: ?>
                    <div class="table-wrap"><table class="table table--compact">
                        <thead><tr><th><?= $h->t('Alert') ?></th><th><?= $h->t('Severity') ?></th><th><?= $h->t('Summary') ?></th><th><?= $h->t('Since') ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($alerts['items'] as $a): ?>
                            <tr><td class="mono"><?= $h->e($a['name']) ?></td>
                                <td><?= $h->badge(match ($a['severity']) { 'critical' => 'critical', 'warning' => 'warning', default => 'info' }, $a['severity']) ?></td>
                                <td class="small"><?= $h->e($a['summary']) ?></td>
                                <td class="small nowrap"><?= $h->ago($a['starts_at']) ?></td></tr>
                        <?php endforeach ?>
                        </tbody>
                    </table></div>
                <?php endif ?>
            </div>

            <?php if ($o['exporters'] !== []): ?>
            <div class="grid grid--3">
                <?php foreach ($o['exporters'] as $group => $values): ?>
                    <div class="card"><div class="card__head"><h2><?= $h->e($group) ?></h2></div>
                        <div class="card__body"><dl class="dl ops-dl-tight"><?php foreach ($values as $k => $v): ?><dt><?= $h->t($k) ?></dt><dd><?= $h->e($v) ?></dd><?php endforeach ?></dl></div></div>
                <?php endforeach ?>
            </div>
            <?php else: ?>
            <div class="card"><div class="card__head"><h2><?= $h->t('Exporters (PostgreSQL / Redis / Kafka)') ?></h2></div>
                <div class="empty small"><?= $h->t('Exporter metrics are available only through Prometheus (monitoring profile).') ?></div></div>
            <?php endif ?>

            <?php if (isset($o['audit_series'])): ?>
            <div class="card"><div class="card__head"><h2><?= $h->t('Audit events per hour (24 h)') ?></h2><span class="small muted"><?= $h->t('database') ?></span></div>
                <div class="card__body"><?= $this->render('./_sparkline', ['values' => $o['audit_series'], 'label' => $h->tr('Audit events per hour')]) ?></div></div>
            <?php endif ?>
        </div>
        <div class="stack">
            <div class="card">
                <div class="card__head"><h2><?= $h->t('Live database gauges') ?></h2></div>
                <table class="table table--compact"><tbody>
                    <tr><td><?= $h->t('Runnable jobs') ?></td><td class="num"><?= (int) $db['runnable'] ?></td></tr>
                    <tr><td><?= $h->t('Oldest runnable job') ?></td><td class="num"><?= (int) $db['oldest_pending_s'] ?> s</td></tr>
                    <tr><td><?= $h->t('Failed/dead jobs (1 h)') ?></td><td class="num"><?= (int) $db['failed_1h'] ?></td></tr>
                    <tr><td><?= $h->t('Outbox pending') ?></td><td class="num"><?= (int) $db['outbox_pending'] ?></td></tr>
                    <tr><td><?= $h->t('Dead letters') ?></td><td class="num"><?= (int) $db['dead_letters'] ?></td></tr>
                    <tr><td><?= $h->t('Login failures (1 h)') ?></td><td class="num"><?= (int) $db['login_failures_1h'] ?></td></tr>
                    <tr><td><?= $h->t('DB connections') ?></td><td class="num"><?= (int) $db['db_connections'] ?></td></tr>
                    <tr><td><?= $h->t('Open incidents') ?></td><td class="num"><?= (int) $db['incidents_open'] ?></td></tr>
                </tbody></table>
            </div>
            <div class="card">
                <div class="card__head"><h2><?= $h->t('Top routes') ?></h2></div>
                <?php if ($o['routes'] === []): ?><div class="empty small"><?= $h->t('No request metrics yet.') ?></div><?php else: ?>
                <table class="table table--compact"><tbody>
                    <?php foreach ($o['routes'] as $r): ?><tr><td class="mono small"><?= $h->e($r['route']) ?></td><td class="num small"><?= $h->e($r['value']) ?></td></tr><?php endforeach ?>
                </tbody></table>
                <?php endif ?>
            </div>
            <p class="small muted"><?= $h->t('Updated {time}', ['time' => date('H:i:s')]) ?></p>
        </div>
    </div>
</div>
