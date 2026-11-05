<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */

$tabs = [
    'admin.auth' => 'Login methods & SSO',
    'admin.auth.mfa' => 'Two-factor (2FA)',
    'admin.auth.mfa.settings' => '2FA settings',
    'admin.auth.sessions' => 'Sessions & sign-in',
];
?>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" aria-label="<?= $h->t('Authentication') ?>">
    <?php foreach ($tabs as $route => $label): ?>
        <a href="<?= $h->url($route) ?>"<?= $active === $route ? ' aria-current="page"' : '' ?>><?= $h->t($label) ?></a>
    <?php endforeach ?>
</nav>
