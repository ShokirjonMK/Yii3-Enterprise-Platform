<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,int> $kpis
 */

$tiles = [
    ['users', 'Users', $kpis['users_active'] ?? 0, $h->tr('{n} total', ['n' => $kpis['users_total'] ?? 0]), 'admin.users'],
    ['activity', 'Active sessions', $kpis['sessions'] ?? 0, $h->tr('last 30 minutes'), 'admin.security'],
    ['clock', 'Jobs pending', $kpis['jobs_pending'] ?? 0, $h->tr('{r} running · {f} failed (24 h)', ['r' => $kpis['jobs_running'] ?? 0, 'f' => $kpis['jobs_failed'] ?? 0]), 'admin.jobs'],
    ['stream', 'Outbox pending', $kpis['outbox_pending'] ?? 0, $h->tr('{n} dead letters', ['n' => $kpis['dlq'] ?? 0]), 'admin.kafka'],
    ['server', 'Workers alive', $kpis['workers_alive'] ?? 0, $h->tr('heartbeat < 90 s'), 'admin.jobs'],
    ['alert', 'Open incidents', $kpis['incidents_open'] ?? 0, '', 'admin.incidents'],
    ['flow', 'Pending approvals', $kpis['approvals_pending'] ?? 0, '', 'admin.workflow'],
    ['file', 'Audit events (24 h)', $kpis['audit_24h'] ?? 0, $h->tr('{n} failed sign-ins', ['n' => $kpis['login_failures'] ?? 0]), 'admin.audit'],
];
?>
<div id="kpis" class="grid grid--4" hx-get="<?= $h->url('admin.dashboard.kpis') ?>" hx-trigger="every 30s" hx-swap="outerHTML">
    <?php foreach ($tiles as [$icon, $label, $value, $meta, $route]): ?>
        <a class="card kpi" href="<?= $h->e($h->urlOrNull($route) ?? '#') ?>">
            <div class="kpi__label"><?= $h->icon($icon) ?> <?= $h->t($label) ?></div>
            <div class="kpi__value"><?= number_format((int) $value, 0, '.', ' ') ?></div>
            <div class="kpi__meta"><?= $h->e($meta) ?></div>
        </a>
    <?php endforeach ?>
</div>
