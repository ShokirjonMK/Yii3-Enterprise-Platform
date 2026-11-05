<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */
?>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" aria-label="<?= $h->t('Storage & Search') ?>">
    <a href="<?= $h->url('admin.storage') ?>"<?= $active === 'admin.storage' ? ' aria-current="page"' : '' ?>><?= $h->t('Object storage') ?></a>
    <a href="<?= $h->url('admin.storage.search') ?>"<?= $active === 'admin.storage.search' ? ' aria-current="page"' : '' ?>><?= $h->t('Search diagnostics') ?></a>
</nav>
