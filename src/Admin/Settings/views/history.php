<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $key
 * @var array $def
 * @var string $scope
 * @var string|null $tenant
 * @var list<array<string,mixed>> $versions
 * @var string $source
 * @var mixed $current
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($h->tr('History of {key}', ['key' => $key]));
$this->setParameter('breadcrumbs', [$h->tr('System Settings') => $h->url('admin.settings', [], ['group' => $def['group'] ?? '']), $key]);
$manage = $can('settings.manage');
$show = static fn($v) => is_scalar($v) || $v === null ? var_export($v, true) : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t($def['label'] ?? $key) ?></h1>
    <p class="page-head__sub row"><span class="mono"><?= $h->e($key) ?></span> <span class="tag"><?= $h->e($scope . ($tenant ? ':' . substr($tenant, 0, 8) : '')) ?></span> <?= $h->badge('info', $h->tr('source: {s}', ['s' => $source])) ?>
        <?php if ($def['type'] !== 'secret'): ?><span class="small"><?= $h->t('effective') ?>: <code><?= $h->e($show($current)) ?></code></span><?php endif ?></p></div>
    <?php if ($manage): ?>
    <div class="page-head__actions"><form method="post" action="<?= $h->url('admin.settings.reset', ['key' => $key]) ?>" data-confirm="<?= $h->t('Remove the override at this scope? The inherited/default value will apply.') ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="scope" value="<?= $h->e($scope) ?>"><input type="hidden" name="tenant" value="<?= $h->e($tenant ?? '') ?>">
        <button class="btn" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Reset to default') ?></button></form></div>
    <?php endif ?>
</div>
<div class="card">
    <?php if ($versions === []): ?>
        <div class="empty"><?= $h->t('No changes recorded at this scope.') ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Version') ?></th><th><?= $h->t('Value') ?></th><th><?= $h->t('Changed by') ?></th><th><?= $h->t('When') ?></th><th><?= $h->t('Comment') ?></th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($versions as $i => $ver): ?>
            <tr>
                <td>v<?= (int) $ver['version'] ?><?= $i === 0 ? ' ' . $h->badge('info', $h->tr('latest')) : '' ?></td>
                <td><code><?= $h->e($def['type'] === 'secret' ? '***' : $show($ver['value'])) ?></code></td>
                <td class="small"><?= $h->e($ver['changed_by_name'] ?? '—') ?></td>
                <td class="small nowrap"><?= $h->datetime($ver['changed_at'], 'Y-m-d H:i:s') ?></td>
                <td class="small"><?= $h->e($ver['comment'] ?? '') ?></td>
                <td class="actions"><?php if ($manage && $i > 0): ?>
                    <form method="post" action="<?= $h->url('admin.settings.rollback', ['key' => $key]) ?>" data-confirm="<?= $h->t('Roll back to version {v}?', ['v' => $ver['version']]) ?>">
                        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="version" value="<?= (int) $ver['version'] ?>">
                        <input type="hidden" name="scope" value="<?= $h->e($scope) ?>"><input type="hidden" name="tenant" value="<?= $h->e($tenant ?? '') ?>">
                        <button class="btn btn--sm" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Roll back') ?></button></form>
                <?php endif ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <div class="card__foot"><span class="small muted"><?= $h->t('Rollback and reset require re-authentication; critical keys require a superadmin.') ?></span></div>
</div>
