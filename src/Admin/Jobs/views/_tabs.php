<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */

$tabs = [
    'admin.jobs' => 'Queues & jobs',
    'admin.jobs.failed' => 'Failed & dead',
    'admin.jobs.workers' => 'Workers',
    'admin.jobs.schedules' => 'Schedules',
];
?>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" aria-label="<?= $h->t('Jobs & Scheduler') ?>">
    <?php foreach ($tabs as $route => $label): ?>
        <a href="<?= $h->url($route) ?>"<?= $active === $route ? ' aria-current="page"' : '' ?>><?= $h->t($label) ?></a>
    <?php endforeach ?>
</nav>
