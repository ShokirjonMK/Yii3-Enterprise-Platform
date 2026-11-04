<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string, array{status:string, latency_ms:float, details:array}> $checks
 */

$labels = ['postgres' => 'PostgreSQL', 'redis' => 'Redis', 'kafka' => 'Kafka', 'storage' => 'Object storage', 'mailer' => 'Mail (SMTP)', 'workers' => 'Workers', 'disk' => 'Disk'];
?>
<table class="table table--compact">
    <tbody>
    <?php foreach ($checks as $name => $c):
        $tone = match ($c['status']) { 'up' => 'ok', 'degraded' => 'warn', 'not_configured' => 'muted', default => 'bad' }; ?>
        <tr>
            <td><span class="dot dot--<?= $tone ?>"></span> <?= $h->e($labels[$name] ?? $name) ?></td>
            <td><?= $h->badge($c['status'] === 'up' ? 'healthy' : $c['status'], $h->tr($c['status'] === 'not_configured' ? 'Not configured' : ucfirst($c['status']))) ?></td>
            <td class="num small muted"><?= $h->e($c['latency_ms']) ?> ms</td>
        </tr>
        <?php if (isset($c['details']['error'])): ?>
            <tr><td colspan="3" class="small muted"><?= $h->e(mb_strimwidth((string) $c['details']['error'], 0, 140, '…')) ?></td></tr>
        <?php endif ?>
    <?php endforeach ?>
    </tbody>
</table>
<p class="small muted card__body"><?= $h->t('Updated {time}', ['time' => date('H:i:s')]) ?></p>
