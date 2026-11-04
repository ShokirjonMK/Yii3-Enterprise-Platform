<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */

$tabs = [
    'admin.kafka' => 'Cluster & topics',
    'admin.kafka.consumers' => 'Consumer groups',
    'admin.kafka.handlers' => 'Handlers',
    'admin.kafka.outbox' => 'Outbox',
    'admin.kafka.dlq' => 'Dead letters',
    'admin.kafka.provision' => 'Provisioning',
    'admin.kafka.publish' => 'Publish',
    'admin.kafka.settings' => 'Settings',
];
?>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" aria-label="<?= $h->t('Kafka Management') ?>">
    <?php foreach ($tabs as $route => $label): ?>
        <a href="<?= $h->url($route) ?>"<?= $active === $route ? ' aria-current="page"' : '' ?>><?= $h->t($label) ?></a>
    <?php endforeach ?>
</nav>
