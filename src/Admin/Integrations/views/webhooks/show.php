<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database\Sql;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $row
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $query
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($row['name']);
$this->setParameter('breadcrumbs', [$h->tr('Integrations') => $h->url('admin.integrations'), $h->tr('Inbound webhooks') => $h->url('admin.integrations.webhooks'), $row['code']]);
$enabled = Sql::bool($row['enabled']);
$manage = $can('integrations.manage');
$args = ['id' => $row['id']];
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->e($row['name']) ?> <span class="tag"><?= $h->e($row['code']) ?></span></h1>
        <p class="page-head__sub row"><?= $enabled ? $h->badge('enabled') : $h->badge('disabled') ?> <span class="mono">POST /webhooks/<?= $h->e($row['code']) ?></span></p></div>
    <?php if ($manage): ?>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('admin.integrations.webhooks.edit', $args) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
        <form method="post" action="<?= $h->url('admin.integrations.webhooks.toggle', $args) ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
            <button class="btn<?= $enabled ? ' btn--danger' : ' btn--primary' ?>" type="submit"><?= $h->icon($enabled ? 'pause' : 'play') ?> <?= $enabled ? $h->t('Disable') : $h->t('Enable') ?></button></form>
        <form method="post" action="<?= $h->url('admin.integrations.webhooks.rotate', $args) ?>" data-confirm="<?= $h->t('Rotate the signing secret? The sender must be updated immediately.') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <button class="btn" type="submit"><?= $h->icon('key') ?> <?= $h->t('Rotate secret') ?></button></form>
        <form method="post" action="<?= $h->url('admin.integrations.webhooks.delete', $args) ?>" data-confirm="<?= $h->t('Delete endpoint {code} and its events?', ['code' => $row['code']]) ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button></form>
    </div>
    <?php endif ?>
</div>
<div class="grid grid--sidebar">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Received events') ?></h2></div>
        <?php if ($page->items === []): ?>
            <div class="empty"><?= $h->icon('bell') ?><div><?= $h->t('No events received yet.') ?></div></div>
        <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th><?= $h->sortLink($h->tr('Received'), 'received_at', $page->sort, $query, 'admin.integrations.webhooks.show', $args) ?></th><th><?= $h->t('External id') ?></th><th><?= $h->t('Signature') ?></th><th><?= $h->t('Processed') ?></th><th><?= $h->t('Job') ?></th></tr></thead>
            <tbody>
            <?php foreach ($page->items as $ev): ?>
                <tr>
                    <td class="small nowrap"><a href="<?= $h->url('admin.integrations.webhooks.event', $args + ['eventId' => $ev['id']]) ?>"><?= $h->datetime($ev['received_at'], 'm-d H:i:s') ?></a></td>
                    <td class="mono small"><?= $h->e(mb_strimwidth((string) $ev['external_id'], 0, 60, '…')) ?></td>
                    <td><?= Sql::bool($ev['signature_valid']) ? $h->badge('ok', $h->tr('valid')) : $h->badge('failed', $h->tr('invalid')) ?></td>
                    <td><?= $ev['processed_at'] ? $h->badge('done', $h->ago($ev['processed_at'])) : $h->badge('pending') ?></td>
                    <td class="mono small"><?= $h->e($ev['job_id'] ? substr((string) $ev['job_id'], 0, 8) : '—') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php endif ?>
        <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.integrations.webhooks.show', 'args' => $args, 'query' => $query]) ?>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Configuration') ?></h2></div>
        <div class="card__body"><dl class="dl">
            <dt><?= $h->t('Signature header') ?></dt><dd class="mono"><?= $h->e($row['signature_header']) ?></dd>
            <dt><?= $h->t('Timestamp header') ?></dt><dd class="mono"><?= $h->e($row['timestamp_header']) ?></dd>
            <dt><?= $h->t('Event id header') ?></dt><dd class="mono"><?= $h->e($row['id_header']) ?></dd>
            <dt><?= $h->t('Tolerance') ?></dt><dd><?= (int) $row['tolerance_seconds'] ?> s</dd>
            <dt><?= $h->t('Job type') ?></dt><dd class="mono"><?= $h->e($row['job_type'] ?? 'platform.webhooks.ack') ?></dd>
            <dt><?= $h->t('Secret') ?></dt><dd><?= $row['secret_enc'] ? $h->badge('ok', $h->tr('set')) : $h->badge('failed', $h->tr('missing')) ?> <span class="small muted"><?= $h->t('rotated {ago}', ['ago' => $h->ago($row['secret_rotated_at'])]) ?></span></dd>
            <dt><?= $h->t('Last received') ?></dt><dd><?= $h->datetime($row['last_received_at']) ?></dd>
        </dl></div>
    </div>
</div>
