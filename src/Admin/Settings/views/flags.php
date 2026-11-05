<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $flags
 * @var string $q
 */
$this->setTitle($h->tr('Feature flags'));
$this->setParameter('breadcrumbs', [$h->tr('System Settings') => $h->url('admin.settings'), $h->tr('Feature flags')]);
?>
<?= $this->render('./_tabs', ['active' => 'flags']) ?>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.settings.flags') ?>" hx-get="<?= $h->url('admin.settings.flags') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
        <input type="search" name="q" value="<?= $h->e($q) ?>" placeholder="<?= $h->t('Search flags…') ?>" aria-label="<?= $h->t('Search') ?>">
        <button class="btn" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>
    <div class="table-wrap"><table class="table">
        <thead><tr><th><?= $h->t('Flag') ?></th><th><?= $h->t('Status') ?></th><th><?= $h->t('Effective') ?></th><th><?= $h->t('Rollout') ?></th><th><?= $h->t('Targeting') ?></th><th><?= $h->t('Dependencies') ?></th></tr></thead>
        <tbody>
        <?php foreach ($flags as $f): ?>
            <tr data-flag="<?= $h->e($f['code']) ?>">
                <td><a class="mono" href="<?= $h->url('admin.settings.flags.show', ['code' => $f['code']]) ?>"><?= $h->e($f['code']) ?></a><div class="small muted"><?= $h->e($f['name']) ?></div></td>
                <td><?= $h->badge($f['status']) ?><?= $f['is_critical'] ? ' ' . $h->badge('critical', $h->tr('critical')) : '' ?></td>
                <td><?= $f['effective'] ? $h->badge('ok', $h->tr('on')) : $h->badge('muted', $h->tr('off')) ?></td>
                <td><div class="bar"><span class="w-<?= (int) (round($f['rollout_percentage'] / 5) * 5) ?>"></span></div><span class="small"><?= (int) $f['rollout_percentage'] ?>%</span></td>
                <td class="small"><?= $f['tenant_allowlist'] !== [] ? $h->t('{n} tenants allowlisted', ['n' => count($f['tenant_allowlist'])]) : '' ?>
                    <?= $f['overrides'] !== [] ? $h->t('{n} overrides', ['n' => count($f['overrides'])]) : '' ?>
                    <?= $f['scheduled_enable_at'] ? $h->t('on at {d}', ['d' => $h->datetime($f['scheduled_enable_at'])]) : '' ?>
                    <?= $f['scheduled_disable_at'] ? $h->t('off at {d}', ['d' => $h->datetime($f['scheduled_disable_at'])]) : '' ?></td>
                <td class="small"><?php foreach ($f['dependencies'] as $d): ?><span class="tag"><?= $h->e($d) ?></span><?php endforeach ?>
                    <?php foreach ($f['problems'] as $p): ?><div class="field__error"><?= $h->e($p) ?></div><?php endforeach ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>
