<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var string $filter
 */

$this->setTitle($h->tr('Notifications'));
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('Notifications') ?></h1></div>
    <div class="page-head__actions">
        <form method="post" action="<?= $h->url('web.notifications.read_all') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button class="btn" type="submit"><?= $h->icon('check') ?> <?= $h->t('Mark all as read') ?></button></form>
    </div>
</div>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <a href="<?= $h->url('web.notifications') ?>"<?= $filter !== 'unread' ? ' aria-current="page"' : '' ?>><?= $h->t('All') ?></a>
    <a href="<?= $h->url('web.notifications', [], ['filter' => 'unread']) ?>"<?= $filter === 'unread' ? ' aria-current="page"' : '' ?>><?= $h->t('Unread') ?></a>
</nav>
<div class="card">
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('bell') ?><p><?= $h->t('No notifications yet.') ?></p></div>
    <?php else: ?>
        <ul class="timeline card__body">
            <?php foreach ($page->items as $n): ?>
                <li>
                    <div class="row row--between">
                        <strong><?= $h->e($n['subject'] ?: $h->tr('Notification')) ?></strong>
                        <span class="small muted"><?= $h->ago($n['created_at']) ?></span>
                    </div>
                    <div><?= nl2br($h->e($n['body'])) ?></div>
                    <?php if ($n['read_at'] === null): ?>
                        <form method="post" action="<?= $h->url('web.notifications.read', ['id' => $n['id']]) ?>" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button class="btn btn--sm btn--ghost" type="submit"><?= $h->t('Mark as read') ?></button></form>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
        <?= $this->render('//components/pager', ['page' => $page, 'route' => 'web.notifications', 'query' => ['filter' => $filter]]) ?>
    <?php endif ?>
</div>
