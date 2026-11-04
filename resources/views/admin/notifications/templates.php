<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $query
 * @var list<string> $channels
 * @var callable(string):bool $can
 */
$this->setTitle($h->tr('Notification templates'));
$this->setParameter('breadcrumbs', [$h->tr('Messaging & Notifications') => $h->url('admin.notifications'), $h->tr('Templates')]);
$sort = $page->sort;
?>
<?= $this->render('./_tabs', ['active' => 'templates']) ?>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.notifications.templates') ?>" hx-get="<?= $h->url('admin.notifications.templates') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="submit, change from:select">
        <input type="search" name="q" value="<?= $h->e($query['q'] ?? '') ?>" placeholder="<?= $h->t('Search code or subject…') ?>" aria-label="<?= $h->t('Search') ?>">
        <select name="channel" aria-label="<?= $h->t('Channel') ?>"><option value=""><?= $h->t('All channels') ?></option>
            <?php foreach ($channels as $c): ?><option value="<?= $h->e($c) ?>"<?= ($query['channel'] ?? '') === $c ? ' selected' : '' ?>><?= $h->e($c) ?></option><?php endforeach ?></select>
        <select name="locale" aria-label="<?= $h->t('Locale') ?>"><option value=""><?= $h->t('All locales') ?></option>
            <?php foreach (App\Platform\Integrations\NotificationAdminService::LOCALES as $l): ?><option value="<?= $l ?>"<?= ($query['locale'] ?? '') === $l ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
        <button class="btn" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
        <span class="topbar__spacer"></span>
        <?php if ($can('notifications.manage')): ?><a class="btn btn--primary" href="<?= $h->url('admin.notifications.templates.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New template') ?></a><?php endif ?>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('file') ?><div><?= $h->t('No templates match.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('Code'), 'code', $sort, $query, 'admin.notifications.templates') ?></th>
            <th><?= $h->sortLink($h->tr('Channel'), 'channel', $sort, $query, 'admin.notifications.templates') ?></th>
            <th><?= $h->sortLink($h->tr('Locale'), 'locale', $sort, $query, 'admin.notifications.templates') ?></th>
            <th><?= $h->t('Subject') ?></th><th><?= $h->t('Version') ?></th>
            <th><?= $h->sortLink($h->tr('Updated'), 'updated_at', $sort, $query, 'admin.notifications.templates') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $t): ?>
            <tr>
                <td><a class="mono" href="<?= $h->url('admin.notifications.templates.edit', ['id' => $t['id']]) ?>"><?= $h->e($t['code']) ?></a></td>
                <td><span class="tag"><?= $h->e($t['channel']) ?></span></td>
                <td><?= $h->e($t['locale']) ?></td>
                <td class="small"><?= $h->e(mb_strimwidth((string) $t['subject'], 0, 80, '…')) ?></td>
                <td class="small"><?= (int) $t['version'] ?></td>
                <td class="small nowrap"><?= $h->ago($t['updated_at']) ?> <?= $h->e($t['updated_by_name'] ?? '') ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.notifications.templates', 'query' => $query]) ?>
</div>
