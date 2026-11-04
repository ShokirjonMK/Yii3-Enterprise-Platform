<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $channels
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($h->tr('Messaging & Notifications'));
$this->setParameter('breadcrumbs', [$h->tr('Messaging & Notifications')]);
$manage = $can('notifications.manage');
?>
<?= $this->render('./_tabs', ['active' => 'channels']) ?>
<div class="card">
    <div class="card__head"><h2><?= $h->t('Channels') ?></h2>
        <?php if ($can('settings.view') && $h->urlOrNull('admin.settings')): ?><a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.settings', [], ['group' => 'Notifications']) ?>"><?= $h->icon('settings') ?> <?= $h->t('Provider settings') ?></a><?php endif ?></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th><?= $h->t('Channel') ?></th><th><?= $h->t('Enabled') ?></th><th><?= $h->t('Health') ?></th><th><?= $h->t('Last 24 h') ?></th><th class="actions"><?= $h->t('Actions') ?></th></tr></thead>
        <tbody>
        <?php foreach ($channels as $c): $enabled = (bool) $c['enabled']; ?>
            <tr>
                <td><strong><?= $h->e($c['name']) ?></strong> <span class="tag"><?= $h->e($c['code']) ?></span><?php if (!$c['adapter']): ?> <?= $h->badge('failed', $h->tr('no adapter')) ?><?php endif ?></td>
                <td><?= $enabled ? $h->badge('enabled') : $h->badge('disabled') ?></td>
                <td id="health-<?= $h->e($c['code']) ?>"><?= $h->badge($c['status'], $c['status'] === 'unconfigured' ? $h->tr('Not configured') : null) ?><?php if ($c['last_error']): ?><div class="small muted"><?= $h->e(mb_strimwidth((string) $c['last_error'], 0, 120, '…')) ?></div><?php endif ?></td>
                <td class="small"><?php foreach ($c['stats'] as $st => $n): ?><span class="tag"><?= $h->e($st) ?>: <?= (int) $n ?></span><?php endforeach ?><?= $c['stats'] === [] ? '—' : '' ?></td>
                <td class="actions">
                    <div class="btn-group">
                        <button class="btn btn--sm" hx-post="<?= $h->url('admin.notifications.health', ['code' => $c['code']]) ?>" hx-target="#health-<?= $h->e($c['code']) ?>" hx-swap="innerHTML"><?= $h->icon('activity') ?> <?= $h->t('Check') ?></button>
                        <?php if ($manage): ?>
                            <?php if ($c['code'] !== 'inapp'): ?>
                            <form method="post" action="<?= $h->url('admin.notifications.toggle', ['code' => $c['code']]) ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                                <button class="btn btn--sm<?= $enabled ? ' btn--danger' : '' ?>" type="submit"><?= $enabled ? $h->t('Disable') : $h->t('Enable') ?></button></form>
                            <?php endif ?>
                            <form method="post" action="<?= $h->url('admin.notifications.test', ['code' => $c['code']]) ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                                <button class="btn btn--sm" type="submit"<?= $enabled ? '' : ' disabled' ?>><?= $h->icon('bell') ?> <?= $h->t('Test to me') ?></button></form>
                        <?php endif ?>
                    </div>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <div class="card__foot"><span class="small muted"><?= $h->t('Telegram: bot token + per-user chat id. SMS: HTTPS gateway (JSON: to, text, from). Web Push: VAPID keys; browsers subscribe via /notifications/push/subscribe.') ?></span></div>
</div>
