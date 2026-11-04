<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 */
?>
<nav class="tabs" aria-label="<?= $h->t('Section') ?>" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <a href="<?= $h->url('admin.rbac') ?>"<?= $active === 'roles' ? ' aria-current="page"' : '' ?>><?= $h->t('Roles') ?></a>
    <a href="<?= $h->url('admin.rbac.matrix') ?>"<?= $active === 'matrix' ? ' aria-current="page"' : '' ?>><?= $h->t('Permission matrix') ?></a>
</nav>
