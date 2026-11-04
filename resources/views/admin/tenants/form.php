<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string|null $id
 * @var array $values
 * @var array $errors
 * @var string $csrf
 */

$editing = $id !== null;
$title = $editing ? $h->tr('Edit tenant') : $h->tr('New tenant');
$this->setTitle($title);
$this->setParameter('breadcrumbs', $editing
    ? [$h->tr('Multi-Tenancy') => $h->url('admin.tenants'), (string) ($values['code'] ?? '') => $h->url('admin.tenants.view', ['id' => $id]), $h->tr('Edit')]
    : [$h->tr('Multi-Tenancy') => $h->url('admin.tenants'), $title]);
$f = fn(array $p) => $this->render('//admin/users/_field', $p + ['errors' => $errors]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($title) ?></h1></div></div>
<div class="card">
    <form class="card__body form form--2" method="post" novalidate action="<?= $editing ? $h->url('admin.tenants.update', ['id' => $id]) : $h->url('admin.tenants.store') ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <?php if ($editing): ?>
            <div class="field"><span class="label"><?= $h->t('Code') ?></span><div class="mono"><?= $h->e($values['code'] ?? '') ?></div><div class="field__hint"><?= $h->t('The code is a stable identifier and cannot be changed.') ?></div></div>
        <?php else: ?>
            <?= $f(['name' => 'code', 'label' => $h->tr('Code'), 'value' => $values['code'] ?? '', 'required' => true, 'hint' => $h->tr('2–64 lowercase letters, digits, - or _. Cannot be changed later.')]) ?>
        <?php endif ?>
        <?= $f(['name' => 'name', 'label' => $h->tr('Name'), 'value' => $values['name'] ?? '', 'required' => true]) ?>
        <?= $f(['name' => 'domain', 'label' => $h->tr('Domain'), 'value' => $values['domain'] ?? '', 'hint' => $h->tr('Optional host name used to recognise the tenant, e.g. tenant.example.uz'), 'attrs' => ['spellcheck' => 'false']]) ?>
        <div class="span-2 row">
            <button type="submit" class="btn btn--primary"><?= $h->icon('check') ?> <?= $editing ? $h->t('Save changes') : $h->t('Create tenant') ?></button>
            <a class="btn btn--ghost" href="<?= $editing ? $h->url('admin.tenants.view', ['id' => $id]) : $h->url('admin.tenants') ?>"><?= $h->t('Cancel') ?></a>
        </div>
    </form>
</div>
