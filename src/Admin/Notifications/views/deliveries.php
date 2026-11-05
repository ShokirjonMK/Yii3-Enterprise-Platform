<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $query
 * @var list<string> $statuses
 * @var list<string> $channels
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($h->tr('Notification deliveries'));
$this->setParameter('breadcrumbs', [$h->tr('Messaging & Notifications') => $h->url('admin.notifications'), $h->tr('Deliveries')]);
$sort = $page->sort;
$route = 'admin.notifications.deliveries';
?>
<?= $this->render('./_tabs', ['active' => 'deliveries']) ?>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url($route) ?>" hx-get="<?= $h->url($route) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="submit, change from:select">
        <input type="search" name="q" value="<?= $h->e($query['q'] ?? '') ?>" placeholder="<?= $h->t('Recipient, subject, user…') ?>" aria-label="<?= $h->t('Search') ?>">
        <select name="status" aria-label="<?= $h->t('Status') ?>"><option value=""><?= $h->t('All statuses') ?></option>
            <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= ($query['status'] ?? '') === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?></select>
        <select name="channel" aria-label="<?= $h->t('Channel') ?>"><option value=""><?= $h->t('All channels') ?></option>
            <?php foreach ($channels as $c): ?><option value="<?= $h->e($c) ?>"<?= ($query['channel'] ?? '') === $c ? ' selected' : '' ?>><?= $h->e($c) ?></option><?php endforeach ?></select>
        <input type="text" name="template" value="<?= $h->e($query['template'] ?? '') ?>" placeholder="<?= $h->t('Template code') ?>" aria-label="<?= $h->t('Template code') ?>">
        <input type="date" name="from" value="<?= $h->e($query['from'] ?? '') ?>" aria-label="<?= $h->t('From') ?>">
        <input type="date" name="to" value="<?= $h->e($query['to'] ?? '') ?>" aria-label="<?= $h->t('To') ?>">
        <button class="btn" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>
    <?php if ($can('notifications.manage') && ($query['status'] ?? '') === 'failed' && $page->total > 0): ?>
    <form class="toolbar" method="post" action="<?= $h->url('admin.notifications.retry_failed') ?>" data-confirm="<?= $h->t('Re-queue up to 500 failed deliveries?') ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="channel" value="<?= $h->e($query['channel'] ?? '') ?>">
        <button class="btn btn--sm" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Retry all failed') ?></button>
    </form>
    <?php endif ?>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('bell') ?><div><?= $h->t('No deliveries match.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('Created'), 'created_at', $sort, $query, $route) ?></th>
            <th><?= $h->sortLink($h->tr('Channel'), 'channel', $sort, $query, $route) ?></th>
            <th><?= $h->t('Recipient') ?></th><th><?= $h->t('Template') ?></th>
            <th><?= $h->sortLink($h->tr('Status'), 'status', $sort, $query, $route) ?></th>
            <th class="num"><?= $h->sortLink($h->tr('Attempts'), 'attempts', $sort, $query, $route) ?></th>
            <th><?= $h->t('Error') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $n): ?>
            <tr>
                <td class="small nowrap"><a href="<?= $h->url('admin.notifications.delivery', ['id' => $n['id']]) ?>"><?= $h->datetime($n['created_at'], 'm-d H:i:s') ?></a></td>
                <td><span class="tag"><?= $h->e($n['channel']) ?></span></td>
                <td class="small"><?= $h->e($n['username'] ?? '') ?> <span class="muted mono"><?= $h->e(mb_strimwidth((string) $n['recipient'], 0, 40, '…')) ?></span></td>
                <td class="mono small"><?= $h->e($n['template_code'] ?? '') ?></td>
                <td><?= $h->badge($n['status']) ?></td>
                <td class="num"><?= (int) $n['attempts'] ?></td>
                <td class="small"><?= $h->e(mb_strimwidth((string) ($n['last_error'] ?? ''), 0, 80, '…')) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => $route, 'query' => $query]) ?>
</div>
