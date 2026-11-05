<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $flag
 * @var bool $effective
 * @var list<string> $problems
 * @var list<array{id:string, code:string, name:string}> $tenants
 * @var array<string,string> $tenantNames
 * @var array<string, list<string>> $errors
 * @var list<string> $statuses
 * @var callable(string):bool $can
 * @var string $csrf
 */
$this->setTitle($flag['code']);
$this->setParameter('breadcrumbs', [$h->tr('System Settings') => $h->url('admin.settings'), $h->tr('Feature flags') => $h->url('admin.settings.flags'), $flag['code']]);
$manage = $can('settings.manage');
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field__error">' . $h->e(implode(' ', $errors[$k])) . '</div>' : '';
$local = static fn(?string $v): string => $v === null ? '' : (new DateTimeImmutable($v))->setTimezone(new DateTimeZone('Asia/Tashkent'))->format('Y-m-d\TH:i');
?>
<div class="page-head">
    <div class="page-head__titles"><h1><span class="mono"><?= $h->e($flag['code']) ?></span></h1>
        <p class="page-head__sub row"><?= $h->e($flag['name']) ?> · <?= $h->badge($flag['status']) ?> <span data-effective="<?= $effective ? 'on' : 'off' ?>"><?= $effective ? $h->badge('ok', $h->tr('effective: on')) : $h->badge('muted', $h->tr('effective: off')) ?></span>
            <?= $flag['is_critical'] ? $h->badge('critical', $h->tr('critical control')) : '' ?></p></div>
    <?php if ($manage): ?>
    <div class="page-head__actions">
        <?php if (!$flag['enabled'] || $flag['status'] === 'disabled'): ?>
            <form method="post" action="<?= $h->url('admin.settings.flags.enable', ['code' => $flag['code']]) ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button class="btn btn--primary" type="submit"<?= $problems !== [] ? ' disabled' : '' ?>><?= $h->icon('play') ?> <?= $h->t('Enable') ?></button></form>
        <?php elseif (!$flag['is_critical']): ?>
            <form method="post" action="<?= $h->url('admin.settings.flags.disable', ['code' => $flag['code']]) ?>" data-confirm="<?= $h->t('Disable {code}?', ['code' => $flag['code']]) ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button class="btn btn--danger" type="submit"><?= $h->icon('pause') ?> <?= $h->t('Disable') ?></button></form>
        <?php endif ?>
    </div>
    <?php endif ?>
</div>
<?php if ($flag['description'] !== ''): ?><p class="muted"><?= $h->e($flag['description']) ?></p><?php endif ?>
<?php foreach ($problems as $p): ?><div class="alert alert--warning"><?= $h->icon('alert') ?> <?= $h->t('Dependency problem') ?>: <?= $h->e($p) ?></div><?php endforeach ?>
<div class="grid grid--2">
    <form class="card" method="post" action="<?= $h->url('admin.settings.flags.configure', ['code' => $flag['code']]) ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
        <div class="card__head"><h2><?= $h->t('Rollout') ?></h2></div>
        <div class="card__body form">
            <div class="field<?= isset($errors['status']) ? ' field--invalid' : '' ?>"><label for="ff-status"><?= $h->t('Operational status') ?></label>
                <select id="ff-status" name="status"<?= $manage ? '' : ' disabled' ?>>
                    <option value=""><?= $h->t('(unchanged: {s})', ['s' => $flag['status']]) ?></option>
                    <?php foreach (array_diff($statuses, ['enabled', 'disabled']) as $s): ?><option value="<?= $s ?>"><?= $h->t($s) ?></option><?php endforeach ?>
                </select><div class="field__hint"><?= $h->t('Use Enable/Disable for the switch; failed, maintenance and unconfigured turn the flag off.') ?></div><?= $err('status') ?></div>
            <div class="field<?= isset($errors['rollout_percentage']) ? ' field--invalid' : '' ?>"><label for="ff-pct"><?= $h->t('Rollout percentage') ?></label>
                <input type="number" id="ff-pct" name="rollout_percentage" min="0" max="100" value="<?= (int) $flag['rollout_percentage'] ?>"<?= $manage ? '' : ' disabled' ?>>
                <div class="field__hint"><?= $h->t('Sticky per user (hash of flag + user id).') ?></div><?= $err('rollout_percentage') ?></div>
            <div class="field<?= isset($errors['tenant_allowlist']) ? ' field--invalid' : '' ?>"><label for="ff-allow"><?= $h->t('Tenant allowlist (tenant ids, empty = all)') ?></label>
                <textarea class="code" id="ff-allow" name="tenant_allowlist" rows="3"<?= $manage ? '' : ' disabled' ?>><?= $h->e(implode("\n", $flag['tenant_allowlist'])) ?></textarea><?= $err('tenant_allowlist') ?></div>
            <div class="form form--2">
                <div class="field<?= isset($errors['scheduled_enable_at']) ? ' field--invalid' : '' ?>"><label for="ff-on"><?= $h->t('Enable at (Asia/Tashkent)') ?></label><input type="datetime-local" id="ff-on" name="scheduled_enable_at" value="<?= $h->e($local($flag['scheduled_enable_at'])) ?>"<?= $manage ? '' : ' disabled' ?>><?= $err('scheduled_enable_at') ?></div>
                <div class="field<?= isset($errors['scheduled_disable_at']) ? ' field--invalid' : '' ?>"><label for="ff-off"><?= $h->t('Disable at (Asia/Tashkent)') ?></label><input type="datetime-local" id="ff-off" name="scheduled_disable_at" value="<?= $h->e($local($flag['scheduled_disable_at'])) ?>"<?= $manage ? '' : ' disabled' ?>><?= $err('scheduled_disable_at') ?></div>
            </div>
        </div>
        <?php if ($manage): ?><div class="card__foot"><button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save rollout') ?></button></div><?php endif ?>
    </form>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Tenant overrides') ?></h2></div>
        <?php if ($flag['overrides'] === []): ?><div class="card__body muted"><?= $h->t('No tenant overrides.') ?></div><?php else: ?>
        <div class="table-wrap"><table class="table table--compact"><tbody>
            <?php foreach ($flag['overrides'] as $tid => $on): ?>
                <tr><td><?= $h->e($tenantNames[$tid] ?? $tid) ?></td><td><?= $on ? $h->badge('ok', $h->tr('forced on')) : $h->badge('muted', $h->tr('forced off')) ?></td>
                    <td class="actions"><?php if ($manage): ?><form method="post" action="<?= $h->url('admin.settings.flags.override', ['code' => $flag['code']]) ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="tenant_id" value="<?= $h->e($tid) ?>"><input type="hidden" name="value" value="clear"><button class="btn btn--sm" type="submit"><?= $h->icon('x') ?> <?= $h->t('Remove') ?></button></form><?php endif ?></td></tr>
            <?php endforeach ?>
        </tbody></table></div>
        <?php endif ?>
        <?php if ($manage): ?>
        <form class="card__body row" method="post" action="<?= $h->url('admin.settings.flags.override', ['code' => $flag['code']]) ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <select name="tenant_id" aria-label="<?= $h->t('Tenant') ?>"><?php foreach ($tenants as $t): ?><option value="<?= $h->e($t['id']) ?>"><?= $h->e($t['code'] . ' · ' . $t['name']) ?></option><?php endforeach ?></select>
            <select name="value" aria-label="<?= $h->t('Value') ?>"><option value="on"><?= $h->t('force on') ?></option><option value="off"><?= $h->t('force off') ?></option></select>
            <button class="btn btn--sm" type="submit"><?= $h->icon('plus') ?> <?= $h->t('Add override') ?></button>
        </form>
        <?php endif ?>
        <div class="card__foot"><span class="small muted"><?= $h->t('Dependencies') ?>: <?php foreach ($flag['dependencies'] as $d): ?><span class="tag"><?= $h->e($d) ?></span><?php endforeach ?><?= $flag['dependencies'] === [] ? '—' : '' ?> · v<?= (int) $flag['version'] ?></span></div>
    </div>
</div>
