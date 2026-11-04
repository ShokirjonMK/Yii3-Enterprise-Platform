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
 * @var array<string,string> $parents
 * @var list<string> $types
 * @var list<string> $statuses
 * @var string $csrf
 */

$editing = $id !== null;
$title = $editing ? $h->tr('Edit organization') : $h->tr('New organization');
$this->setTitle($title);
$this->setParameter('breadcrumbs', [$h->tr('Organizations') => $h->url('admin.organizations', [], ['tenant_id' => $values['tenant_id'] ?? '']), $title]);
$v = static fn(string $k, $d = '') => $values[$k] ?? $d;
$f = fn(array $p) => $this->render('//admin/users/_field', $p + ['errors' => $errors]);
$opt = static fn(array $list) => array_combine($list, $list);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->e($title) ?></h1></div></div>

<div class="card">
    <form class="card__body form form--2" method="post" novalidate
          action="<?= $editing ? $h->url('admin.organizations.update', ['id' => $id]) : $h->url('admin.organizations.store') ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <?php if ($editing || !$cross): ?>
            <div class="field"><span class="label"><?= $h->t('Tenant') ?></span><div><?= $h->e($tenants[$v('tenant_id')] ?? '—') ?></div></div>
            <?php if (!$editing): ?><input type="hidden" name="tenant_id" value="<?= $h->e($v('tenant_id')) ?>"><?php endif ?>
        <?php else: ?>
            <?= $f(['name' => 'tenant_id', 'label' => $h->tr('Tenant'), 'type' => 'select', 'value' => $v('tenant_id'), 'options' => $tenants, 'required' => true,
                'attrs' => ['hx-get' => $h->url('admin.organizations.create'), 'hx-trigger' => 'change', 'hx-include' => 'this', 'hx-target' => '#main', 'hx-select' => '#main', 'hx-swap' => 'outerHTML']]) ?>
        <?php endif ?>
        <?= $f(['name' => 'parent_id', 'label' => $h->tr('Parent unit'), 'type' => 'select', 'value' => $v('parent_id'), 'options' => ['' => $h->tr('— top level —')] + $parents,
            'hint' => $editing ? $h->tr('The unit itself and its descendants cannot be selected (no cycles).') : null]) ?>
        <?= $f(['name' => 'code', 'label' => $h->tr('Code'), 'value' => $v('code'), 'required' => true, 'hint' => $h->tr('Unique within the tenant.')]) ?>
        <?= $f(['name' => 'name', 'label' => $h->tr('Name'), 'value' => $v('name'), 'required' => true]) ?>
        <?= $f(['name' => 'type', 'label' => $h->tr('Type'), 'type' => 'select', 'value' => $v('type', 'department'), 'options' => $opt($types)]) ?>
        <?= $f(['name' => 'inn', 'label' => $h->tr('INN (STIR)'), 'value' => $v('inn'), 'hint' => $h->tr('Optional, 9 digits.'), 'attrs' => ['inputmode' => 'numeric', 'maxlength' => '9']]) ?>
        <?= $f(['name' => 'status', 'label' => $h->tr('Status'), 'type' => 'select', 'value' => $v('status', 'active'), 'options' => $opt($statuses)]) ?>
        <div class="span-2 row">
            <button type="submit" class="btn btn--primary"><?= $h->icon('check') ?> <?= $editing ? $h->t('Save changes') : $h->t('Create organization') ?></button>
            <a class="btn btn--ghost" href="<?= $h->url('admin.organizations', [], ['tenant_id' => $v('tenant_id')]) ?>"><?= $h->t('Cancel') ?></a>
        </div>
    </form>
</div>
