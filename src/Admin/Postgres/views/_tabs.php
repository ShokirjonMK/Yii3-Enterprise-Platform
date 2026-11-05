<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */

$tabs = [
    'admin.postgres' => 'Overview',
    'admin.postgres.activity' => 'Activity & locks',
    'admin.postgres.queries' => 'Slow queries',
    'admin.postgres.storage' => 'Tables & indexes',
    'admin.postgres.replication' => 'Replication & WAL',
    'admin.postgres.partitions' => 'Partitions',
];
?>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" aria-label="<?= $h->t('PostgreSQL Management') ?>">
    <?php foreach ($tabs as $route => $label): ?>
        <a href="<?= $h->url($route) ?>"<?= $active === $route ? ' aria-current="page"' : '' ?>><?= $h->t($label) ?></a>
    <?php endforeach ?>
</nav>
