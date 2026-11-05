<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('System Settings') ?></h1>
    <p class="page-head__sub"><?= $h->t('Typed, versioned configuration with environment overrides, tenant scope, rollback and feature flags. Changes propagate to all instances within seconds.') ?></p></div></div>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <a href="<?= $h->url('admin.settings') ?>"<?= $active === 'settings' ? ' aria-current="page"' : '' ?>><?= $h->t('Settings') ?></a>
    <a href="<?= $h->url('admin.settings.flags') ?>"<?= $active === 'flags' ? ' aria-current="page"' : '' ?>><?= $h->t('Feature flags') ?></a>
    <a href="<?= $h->url('admin.appearance') ?>"<?= $active === 'appearance' ? ' aria-current="page"' : '' ?>><?= $h->t('Appearance') ?></a>
</nav>
