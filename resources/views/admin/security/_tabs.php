<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */

$tabs = ['admin.security' => 'Posture & events', 'admin.security.sessions' => 'Sessions & tokens', 'admin.security.asvs' => 'OWASP ASVS'];
?>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <?php foreach ($tabs as $route => $label): ?>
        <a href="<?= $h->url($route) ?>"<?= $active === $route ? ' aria-current="page"' : '' ?>><?= $h->t($label) ?></a>
    <?php endforeach ?>
</nav>
