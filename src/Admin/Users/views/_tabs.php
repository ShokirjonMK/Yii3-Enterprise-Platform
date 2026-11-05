<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */
?>
<nav class="tabs" aria-label="<?= $h->t('Section') ?>" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <a href="<?= $h->url('admin.users') ?>"<?= $active === 'users' ? ' aria-current="page"' : '' ?>><?= $h->t('Users') ?></a>
    <a href="<?= $h->url('admin.organizations') ?>"<?= $active === 'organizations' ? ' aria-current="page"' : '' ?>><?= $h->t('Organizations') ?></a>
</nav>
