<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string|null $id
 * @var array $values
 * @var array $errors
 * @var bool $cross
 * @var array<string,string> $tenants
 * @var string $csrf
 */

$editing = $id !== null;
$title = $editing ? $h->tr('Edit API client') : $h->tr('New API client');
$this->setTitle($title);
$this->setParameter('breadcrumbs', $editing
    ? [$h->tr('API Management') => $h->url('admin.api'), (string) ($values['name'] ?? '') => $h->url('admin.api.clients.view', ['id' => $id]), $h->tr('Edit')]
    : [$h->tr('API Management') => $h->url('admin.api'), $title]);
$f = fn(array $p) => $this->render($h->view('@admin/Users/views/_field'), $p + ['errors' => $errors]);
$v = static fn(string $k, $d = '') => $values[$k] ?? $d;
$joined = static fn($x, string $sep) => is_array($x) ? implode($sep, $x) : (string) $x;
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($title) ?></h1>
    <?php if (!$editing): ?><p class="page-head__sub"><?= $h->t('A client id and secret are generated. The secret is shown once after saving.') ?></p><?php endif ?>
</div></div>
<div class="card">
    <form class="card__body form form--2" method="post" novalidate autocomplete="off" action="<?= $editing ? $h->url('admin.api.clients.update', ['id' => $id]) : $h->url('admin.api.clients.store') ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <?= $f(['name' => 'name', 'label' => $h->tr('Name'), 'value' => $v('name'), 'required' => true]) ?>
        <?php if ($cross && !$editing): ?>
            <?= $f(['name' => 'tenant_id', 'label' => $h->tr('Tenant'), 'type' => 'select', 'value' => $v('tenant_id'), 'options' => ['' => $h->tr('— platform-wide —')] + $tenants]) ?>
        <?php elseif ($editing): ?>
            <div class="field"><span class="label"><?= $h->t('Tenant') ?></span><div><?= $h->e($v('tenant_name') ?: $h->tr('platform-wide')) ?></div></div>
        <?php endif ?>
        <?= $f(['name' => 'scopes', 'label' => $h->tr('Scopes'), 'value' => $joined($v('scopes'), ' '), 'required' => true, 'hint' => $h->tr('Space or comma separated, e.g. projects.read projects.* — use * only for trusted internal services.')]) ?>
        <?= $f(['name' => 'rate_limit_per_minute', 'label' => $h->tr('Rate limit (requests per minute)'), 'type' => 'number', 'value' => $v('rate_limit_per_minute', 600), 'attrs' => ['min' => '1', 'max' => '100000']]) ?>
        <?= $f(['name' => 'allowed_ips', 'label' => $h->tr('Allowed IP addresses / CIDR ranges'), 'type' => 'textarea', 'value' => $joined($v('allowed_ips'), "\n"), 'errorKey' => 'allowed_ips', 'hint' => $h->tr('One per line, e.g. 10.0.0.0/8 or 2001:db8::/32. Empty = any address.')]) ?>
        <div class="stack">
            <?= $f(['name' => 'expires_at', 'label' => $h->tr('Expires at'), 'type' => 'datetime-local', 'value' => $v('expires_at'), 'hint' => $h->tr('Optional. Expired clients are rejected.')]) ?>
            <?= $f(['name' => 'service_username', 'label' => $h->tr('Service account (username)'), 'value' => $v('service_username'), 'hint' => $h->tr('Optional user the client acts as (must belong to the same tenant).')]) ?>
        </div>
        <div class="span-2 row">
            <button type="submit" class="btn btn--primary"><?= $h->icon('check') ?> <?= $editing ? $h->t('Save changes') : $h->t('Create client') ?></button>
            <a class="btn btn--ghost" data-back href="<?= $editing ? $h->url('admin.api.clients.view', ['id' => $id]) : $h->url('admin.api') ?>"><?= $h->t('Cancel') ?></a>
        </div>
    </form>
</div>
