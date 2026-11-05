<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $modules
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($h->tr('Business Modules'));
$this->setParameter('breadcrumbs', [$h->tr('Business Modules')]);
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('Business Modules') ?></h1>
        <p class="page-head__sub"><?= $h->t('Modules found in src/Modules/*/module.php, their installation state and runtime switch (feature flag).') ?></p></div>
    <?php if ($can('modules.manage')): ?>
    <div class="page-head__actions"><form method="post" action="<?= $h->url('admin.modules.sync') ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <button class="btn" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Sync registry') ?></button></form></div>
    <?php endif ?>
</div>
<?php if ($modules === []): ?>
    <div class="card"><div class="empty"><?= $h->icon('grid') ?><div><?= $h->t('No business modules found.') ?></div></div></div>
<?php else: ?>
<div class="grid grid--3">
    <?php foreach ($modules as $m): ?>
    <a class="card" href="<?= $h->url('admin.modules.show', ['code' => $m['code']]) ?>" data-module="<?= $h->e($m['code']) ?>">
        <div class="card__head"><h3><?= $h->icon('grid') ?> <?= $h->e($m['name']) ?></h3><span class="tag">v<?= $h->e($m['version']) ?></span></div>
        <div class="card__body">
            <p class="small muted"><?= $h->e(mb_strimwidth($m['description'], 0, 180, '…')) ?></p>
            <div class="row">
                <?= $m['missing'] ? $h->badge('failed', $h->tr('manifest missing')) : '' ?>
                <?= $m['installed'] ? $h->badge($m['enabled'] ? 'enabled' : 'disabled') : $h->badge('pending', $h->tr('not registered')) ?>
                <span data-flag-state="<?= $m['flag_enabled'] ? 'on' : 'off' ?>"><?= $m['flag_enabled'] ? $h->badge('ok', $h->tr('flag on')) : $h->badge('muted', $h->tr('flag off')) ?></span>
            </div>
            <div class="small mt"><?= $h->t('{r} routes · {p} permissions', ['r' => $m['routes'], 'p' => count($m['permissions'])]) ?></div>
        </div>
    </a>
    <?php endforeach ?>
</div>
<?php endif ?>
