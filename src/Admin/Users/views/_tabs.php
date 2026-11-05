<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 * @var callable(string):bool $can
 */
?>
<nav class="tabs" aria-label="<?= $h->t('Section') ?>" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <a href="<?= $h->url('admin.users') ?>"<?= $active === 'users' ? ' aria-current="page"' : '' ?>><?= $h->t('Users') ?></a>
    <a href="<?= $h->url('admin.organizations') ?>"<?= $active === 'organizations' ? ' aria-current="page"' : '' ?>><?= $h->t('Organizations') ?></a>
    <?php if ($can('users.login_history')): ?><a href="<?= $h->url('admin.users.login_history') ?>"<?= $active === 'login_history' ? ' aria-current="page"' : '' ?>><?= $h->t('Login history') ?></a><?php endif ?>
</nav>
