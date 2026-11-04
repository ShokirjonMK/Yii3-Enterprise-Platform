<?php

declare(strict_types=1);

/**
 * Shown instead of a 404 when an admin opens a section whose optional feature flag is switched off.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $flag
 * @var string $returnPath
 * @var string $csrf
 * @var callable(string):bool $can
 *
 * Author: ShokirjonMK
 */

$this->setTitle($h->tr('Section is switched off'));
$this->setParameter('breadcrumbs', [$h->tr('Section is switched off')]);
?>
<div class="card">
    <div class="card__body">
        <div class="empty">
            <?= $h->icon('flag') ?>
            <h1><?= $h->t('This section is switched off') ?></h1>
            <p><strong><?= $h->e($flag['name'] ?? $flag['code']) ?></strong> <span class="tag mono"><?= $h->e($flag['code']) ?></span></p>
            <?php if (!empty($flag['description'])): ?><p class="muted"><?= $h->e($flag['description']) ?></p><?php endif ?>
            <p class="small muted"><?= $h->t('Optional features are disabled when the project does not use them: the section is hidden from the menu and does no work.') ?></p>
            <?php if ($can('settings.manage')): ?>
                <div class="btn-group">
                    <form method="post" action="<?= $h->url('admin.settings.flags.enable', ['code' => $flag['code']]) ?>">
                        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                        <input type="hidden" name="return" value="<?= $h->e($returnPath) ?>">
                        <button class="btn btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Enable this section') ?></button>
                    </form>
                    <a class="btn" href="<?= $h->url('admin.settings.flags') ?>"><?= $h->icon('settings') ?> <?= $h->t('All feature flags') ?></a>
                </div>
            <?php else: ?>
                <p class="small"><?= $h->t('Ask an administrator with the “settings.manage” permission to enable it.') ?></p>
            <?php endif ?>
        </div>
    </div>
</div>
