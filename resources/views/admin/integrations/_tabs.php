<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */
$tabs = ['integrations' => ['admin.integrations', 'Integrations'], 'logs' => ['admin.integrations.logs', 'Recent calls'], 'webhooks' => ['admin.integrations.webhooks', 'Inbound webhooks']];
?>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <?php foreach ($tabs as $key => [$route, $label]): ?>
        <a href="<?= $h->url($route) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $h->t($label) ?></a>
    <?php endforeach ?>
</nav>
