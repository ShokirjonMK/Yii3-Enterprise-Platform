<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $active
 * @var string $role "state" | "cache"
 * @var bool $cacheSeparate
 */
$role ??= 'state';
$cacheSeparate ??= false;
$q = $role === 'cache' ? ['instance' => 'cache'] : [];
?>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" aria-label="<?= $h->t('Redis Management') ?>">
    <a href="<?= $h->url('admin.redis', [], $q) ?>"<?= $active === 'admin.redis' ? ' aria-current="page"' : '' ?>><?= $h->t('Overview') ?></a>
    <a href="<?= $h->url('admin.redis.keys', [], $q) ?>"<?= $active === 'admin.redis.keys' ? ' aria-current="page"' : '' ?>><?= $h->t('Keys & cache') ?></a>
</nav>
<?php if ($cacheSeparate): ?>
<nav class="tabs tabs--sub" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML" aria-label="<?= $h->t('Redis instance') ?>">
    <a href="<?= $h->url($active, [], []) ?>"<?= $role === 'state' ? ' aria-current="page"' : '' ?>><?= $h->t('State instance (sessions, locks, rate limits)') ?></a>
    <a href="<?= $h->url($active, [], ['instance' => 'cache']) ?>"<?= $role === 'cache' ? ' aria-current="page"' : '' ?>><?= $h->t('Cache instance') ?></a>
</nav>
<?php endif ?>
