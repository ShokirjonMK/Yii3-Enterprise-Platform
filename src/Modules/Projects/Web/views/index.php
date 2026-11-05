<?php

declare(strict_types=1);

/**
 * Web · Projects (my projects / all projects of my organisation).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var string $scope
 * @var array $filters
 * @var array $query
 * @var list<App\Modules\Projects\Domain\ProjectStatus> $statuses
 */

use App\Modules\Projects\Domain\ProjectStatus;

$this->setTitle($h->tr('Projects'));
$hx = 'hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"';
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('Projects') ?></h1></div>
    <div class="page-head__actions">
        <?php if ($can('projects.create')): ?>
            <a class="btn btn--primary" href="<?= $h->url('web.projects.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New project') ?></a>
        <?php endif ?>
    </div>
</div>
<nav class="tabs" hx-boost="true" <?= $hx ?>>
    <a href="<?= $h->url('web.projects', [], ['scope' => 'mine']) ?>"<?= $scope === 'mine' ? ' aria-current="page"' : '' ?>><?= $h->t('My projects') ?></a>
    <a href="<?= $h->url('web.projects', [], ['scope' => 'all']) ?>"<?= $scope === 'all' ? ' aria-current="page"' : '' ?>><?= $h->t('All projects') ?></a>
</nav>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('web.projects') ?>" hx-get="<?= $h->url('web.projects') ?>" <?= $hx ?> hx-trigger="submit, change from:select">
        <input type="hidden" name="scope" value="<?= $h->e($scope) ?>">
        <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Search projects…') ?>" aria-label="<?= $h->t('Search') ?>">
        <select name="status" aria-label="<?= $h->t('Status') ?>">
            <option value=""><?= $h->t('All open statuses') ?></option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= $h->e($s->value) ?>"<?= $filters['status'] === $s->value ? ' selected' : '' ?>><?= $h->t($s->label()) ?></option>
            <?php endforeach ?>
        </select>
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Search') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('folder') ?><p><?= $scope === 'mine' ? $h->t('You are not a member of any project yet.') : $h->t('No projects found.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th><?= $h->t('Code') ?></th><th><?= $h->t('Name') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('Owner') ?></th><th><?= $h->t('Due') ?></th></tr></thead>
            <tbody>
            <?php foreach ($page->items as $p): ?>
                <tr>
                    <td class="nowrap"><a class="mono" href="<?= $h->url('web.projects.view', ['id' => $p['id']]) ?>"><?= $h->e($p['code']) ?></a></td>
                    <td><?= $h->e($p['name']) ?></td>
                    <td><?= $h->badge($p['status'], $h->tr(ProjectStatus::from((string) $p['status'])->label())) ?></td>
                    <td><?= $h->e($p['owner_name'] ?? '') ?></td>
                    <td class="nowrap"><?= $h->e($p['due_on'] ?? '—') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'web.projects', 'query' => $query]) ?>
</div>
