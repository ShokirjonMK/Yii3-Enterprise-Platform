<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */
?>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" aria-label="<?= $h->t('Redis Management') ?>">
    <a href="<?= $h->url('admin.redis') ?>"<?= $active === 'admin.redis' ? ' aria-current="page"' : '' ?>><?= $h->t('Overview') ?></a>
    <a href="<?= $h->url('admin.redis.keys') ?>"<?= $active === 'admin.redis.keys' ? ' aria-current="page"' : '' ?>><?= $h->t('Keys & cache') ?></a>
</nav>
