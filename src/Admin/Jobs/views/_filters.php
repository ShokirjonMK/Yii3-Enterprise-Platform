<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{queue:string,status:string,type:string,q:string} $filters
 * @var list<string> $queues
 * @var list<string> $types
 * @var list<string> $statuses
 * @var string $route
 * @var string $sort
 */

$action = $h->url($route);
?>
<form class="toolbar" method="get" action="<?= $h->e($action) ?>" role="search"
      hx-get="<?= $h->e($action) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"
      hx-trigger="change, keyup changed delay:400ms from:find input[type=search], submit">
    <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Search id, type, error…') ?>" aria-label="<?= $h->t('Search') ?>">
    <select name="queue" aria-label="<?= $h->t('Queue') ?>">
        <option value=""><?= $h->t('All queues') ?></option>
        <?php foreach ($queues as $q): ?><option value="<?= $h->e($q) ?>"<?= $filters['queue'] === $q ? ' selected' : '' ?>><?= $h->e($q) ?></option><?php endforeach ?>
    </select>
    <select name="status" aria-label="<?= $h->t('Status') ?>">
        <option value=""><?= $h->t('All statuses') ?></option>
        <?php foreach ($statuses as $s): ?><option value="<?= $h->e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?>
    </select>
    <select name="type" aria-label="<?= $h->t('Job type') ?>">
        <option value=""><?= $h->t('All types') ?></option>
        <?php foreach ($types as $t): ?><option value="<?= $h->e($t) ?>"<?= $filters['type'] === $t ? ' selected' : '' ?>><?= $h->e($t) ?></option><?php endforeach ?>
    </select>
    <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?= $h->e($sort) ?>"><?php endif ?>
    <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    <?php if (array_filter($filters) !== []): ?><a class="btn btn--sm btn--ghost" href="<?= $h->e($action) ?>"><?= $h->t('Reset') ?></a><?php endif ?>
</form>
