<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $client
 * @var list<array> $history
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($client['name']);
$this->setParameter('breadcrumbs', [$h->tr('API Management') => $h->url('admin.api'), (string) $client['name']]);
$csrfInput = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$id = (string) $client['id'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($client['name']) ?> <?= $client['expired'] ? $h->badge('failed', $h->tr('expired')) : $h->badge($client['enabled'] ? 'enabled' : 'disabled') ?></h1>
        <p class="page-head__sub mono"><?= $h->e($client['client_id']) ?> <button type="button" class="btn btn--sm btn--ghost" data-copy="<?= $h->e($client['client_id']) ?>"><?= $h->t('Copy') ?></button></p>
    </div>
    <?php if ($can('api.manage')): ?>
        <div class="page-head__actions">
            <a class="btn" href="<?= $h->url('admin.api.clients.edit', ['id' => $id]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
            <?php if ($client['enabled']): ?>
                <form method="post" action="<?= $h->url('admin.api.clients.disable', ['id' => $id]) ?>" data-confirm="<?= $h->t('Disable this client? Its credentials stop working immediately.') ?>"><?= $csrfInput ?><button class="btn btn--danger" type="submit"><?= $h->icon('pause') ?> <?= $h->t('Disable') ?></button></form>
            <?php else: ?>
                <form method="post" action="<?= $h->url('admin.api.clients.enable', ['id' => $id]) ?>"><?= $csrfInput ?><button class="btn" type="submit"><?= $h->icon('play') ?> <?= $h->t('Enable') ?></button></form>
            <?php endif ?>
            <form method="post" action="<?= $h->url('admin.api.clients.rotate', ['id' => $id]) ?>" data-confirm="<?= $h->t('Rotate the secret? The current secret stops working immediately.') ?>"><?= $csrfInput ?><button class="btn" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Rotate secret') ?></button></form>
            <form method="post" action="<?= $h->url('admin.api.clients.delete', ['id' => $id]) ?>" data-confirm="<?= $h->t('Delete this API client permanently?') ?>"><?= $csrfInput ?><button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button></form>
        </div>
    <?php endif ?>
</div>

<div class="grid grid--2">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Details') ?></h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt><?= $h->t('Client secret') ?></dt><dd><?= $h->badge('ok', $h->tr('set (Argon2id hash only)')) ?></dd>
                <dt><?= $h->t('Tenant') ?></dt><dd><?= $h->e($client['tenant_name'] ?? $h->tr('platform-wide')) ?></dd>
                <dt><?= $h->t('Service account') ?></dt><dd class="mono"><?= $h->e($client['service_username'] ?? '—') ?></dd>
                <dt><?= $h->t('Scopes') ?></dt><dd><?php foreach ($client['scopes'] as $s): ?><span class="tag"><?= $h->e($s) ?></span><?php endforeach ?></dd>
                <dt><?= $h->t('Allowed IPs') ?></dt><dd><?php if ($client['allowed_ips'] === []): ?><span class="muted"><?= $h->t('any address') ?></span><?php endif ?><?php foreach ($client['allowed_ips'] as $ip): ?><span class="tag"><?= $h->e($ip) ?></span><?php endforeach ?></dd>
                <dt><?= $h->t('Rate limit') ?></dt><dd><?= $h->t('{n} requests / minute', ['n' => (int) $client['rate_limit_per_minute']]) ?></dd>
                <dt><?= $h->t('Expires') ?></dt><dd><?= $h->datetime($client['expires_at']) ?></dd>
                <dt><?= $h->t('Last used') ?></dt><dd><?= $h->datetime($client['last_used_at']) ?> <span class="muted small">(<?= $h->ago($client['last_used_at']) ?>)</span></dd>
                <dt><?= $h->t('Created') ?></dt><dd><?= $h->datetime($client['created_at']) ?></dd>
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Audit history') ?></h2></div>
        <?php if ($history === []): ?>
            <div class="empty"><?= $h->t('No audit records.') ?></div>
        <?php else: ?>
            <ol class="timeline card__body">
                <?php foreach ($history as $a): ?>
                    <li><div><code><?= $h->e($a['action']) ?></code> <?= $h->badge($a['result']) ?></div>
                        <div class="small muted"><?= $h->datetime($a['occurred_at'], 'Y-m-d H:i:s') ?> · <?= $h->e($a['actor_name'] ?? ($a['actor_id'] ? substr((string) $a['actor_id'], 0, 8) : 'system')) ?></div></li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>
    </div>
</div>
