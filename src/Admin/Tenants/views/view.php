<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $tenant
 * @var list<array{code:string, name:string, global:bool, override:?bool, effective:bool, is_critical:bool}> $flags
 * @var int $sessions
 * @var list<array> $history
 * @var bool $mayCross
 * @var bool $mayFlags
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($tenant['name']);
$this->setParameter('breadcrumbs', [$h->tr('Multi-Tenancy') => $h->url('admin.tenants'), (string) $tenant['code']]);
$csrfInput = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$canManage = $can('tenants.manage');
$id = (string) $tenant['id'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->e($tenant['name']) ?> <?= $h->badge($tenant['status'] === 'suspended' ? 'paused' : $tenant['status'], $h->tr($tenant['status'])) ?></h1>
        <p class="page-head__sub mono"><?= $h->e($tenant['code']) ?><?= $tenant['domain'] ? ' · ' . $h->e($tenant['domain']) : '' ?></p>
    </div>
    <?php if ($canManage): ?>
        <div class="page-head__actions">
            <a class="btn" href="<?= $h->url('admin.tenants.edit', ['id' => $id]) ?>"><?= $h->icon('edit') ?> <?= $h->t('Edit') ?></a>
            <?php if ($mayCross): ?>
                <?php if ($tenant['status'] !== 'active'): ?>
                    <form method="post" action="<?= $h->url('admin.tenants.status', ['id' => $id]) ?>"><?= $csrfInput ?><input type="hidden" name="status" value="active"><button class="btn btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Activate') ?></button></form>
                <?php endif ?>
                <?php if ($tenant['status'] === 'active'): ?>
                    <form method="post" action="<?= $h->url('admin.tenants.status', ['id' => $id]) ?>" data-confirm="<?= $h->t('Suspend this tenant? All sessions and API tokens of its users are revoked immediately.') ?>"><?= $csrfInput ?><input type="hidden" name="status" value="suspended"><button class="btn btn--danger" type="submit"><?= $h->icon('pause') ?> <?= $h->t('Suspend') ?></button></form>
                <?php endif ?>
                <?php if ($tenant['status'] !== 'archived'): ?>
                    <form method="post" action="<?= $h->url('admin.tenants.status', ['id' => $id]) ?>" data-confirm="<?= $h->t('Archive this tenant? All sessions and API tokens of its users are revoked.') ?>"><?= $csrfInput ?><input type="hidden" name="status" value="archived"><button class="btn btn--danger" type="submit"><?= $h->icon('archive') ?> <?= $h->t('Archive') ?></button></form>
                <?php endif ?>
                <?php if ((int) $tenant['user_count'] === 0 && (int) $tenant['organization_count'] === 0): ?>
                    <form method="post" action="<?= $h->url('admin.tenants.delete', ['id' => $id]) ?>" data-confirm="<?= $h->t('Delete this tenant permanently?') ?>"><?= $csrfInput ?><button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete') ?></button></form>
                <?php endif ?>
            <?php endif ?>
        </div>
    <?php endif ?>
</div>

<div class="grid grid--4 mb">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('users') ?> <?= $h->t('Users') ?></div><div class="kpi__value"><?= (int) $tenant['user_count'] ?></div><div class="kpi__meta"><?= $h->t('{n} active', ['n' => (int) $tenant['active_user_count']]) ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('building') ?> <?= $h->t('Organizations') ?></div><div class="kpi__value"><?= (int) $tenant['organization_count'] ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('activity') ?> <?= $h->t('Open sessions') ?></div><div class="kpi__value"><?= (int) $sessions ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('clock') ?> <?= $h->t('Created') ?></div><div class="kpi__value small"><?= $h->datetime($tenant['created_at'], 'Y-m-d') ?></div></div>
</div>

<div class="row mb">
    <?php if ($can('users.view')): ?>
        <a class="btn btn--sm" href="<?= $h->url('admin.users', [], ['tenant_id' => $id]) ?>"><?= $h->icon('users') ?> <?= $h->t('Users of this tenant') ?></a>
        <a class="btn btn--sm" href="<?= $h->url('admin.organizations', [], ['tenant_id' => $id]) ?>"><?= $h->icon('building') ?> <?= $h->t('Organization tree') ?></a>
    <?php endif ?>
</div>

<div class="card">
    <div class="card__head"><h2><?= $h->t('Feature flags for this tenant') ?></h2></div>
    <?php if ($flags === []): ?>
        <div class="empty"><?= $h->t('No feature flags defined.') ?></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr><th scope="col"><?= $h->t('Flag') ?></th><th scope="col"><?= $h->t('Global') ?></th><th scope="col"><?= $h->t('Override') ?></th><th scope="col"><?= $h->t('Effective') ?></th><th scope="col"><span class="sr-only"><?= $h->t('Actions') ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($flags as $fl): ?>
                <tr>
                    <td><code><?= $h->e($fl['code']) ?></code><div class="small muted"><?= $h->e($fl['name']) ?></div></td>
                    <td><?= $h->badge($fl['global'] ? 'enabled' : 'disabled') ?></td>
                    <td><?= $fl['override'] === null ? '<span class="muted small">' . $h->t('inherit') . '</span>' : $h->badge($fl['override'] ? 'enabled' : 'disabled', $fl['override'] ? $h->tr('forced on') : $h->tr('forced off')) ?></td>
                    <td><?= $h->badge($fl['effective'] ? 'enabled' : 'disabled') ?></td>
                    <td class="actions">
                        <?php if ($canManage && $mayFlags): ?>
                            <form method="post" action="<?= $h->url('admin.tenants.flags', ['id' => $id]) ?>" class="row">
                                <?= $csrfInput ?><input type="hidden" name="code" value="<?= $h->e($fl['code']) ?>">
                                <label class="sr-only" for="ov-<?= $h->e($fl['code']) ?>"><?= $h->t('Override for {f}', ['f' => $fl['code']]) ?></label>
                                <select id="ov-<?= $h->e($fl['code']) ?>" name="value" class="id-select-sm">
                                    <?php foreach (['inherit' => $h->tr('Inherit'), 'on' => $h->tr('Force on'), 'off' => $h->tr('Force off')] as $val => $lbl):
                                        if ($val === 'off' && $fl['is_critical']) {
                                            continue;
                                        }
                                        $cur = $fl['override'] === null ? 'inherit' : ($fl['override'] ? 'on' : 'off'); ?>
                                        <option value="<?= $val ?>"<?= $cur === $val ? ' selected' : '' ?>><?= $h->e($lbl) ?></option>
                                    <?php endforeach ?>
                                </select>
                                <button class="btn btn--sm" type="submit"><?= $h->t('Apply') ?></button>
                            </form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php if ($canManage && !$mayFlags): ?><div class="card__body small muted"><?= $h->t('Changing overrides additionally requires the settings.manage permission.') ?></div><?php endif ?>
    <?php endif ?>
</div>

<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Audit history') ?></h2></div>
    <?php if ($history === []): ?>
        <div class="empty"><?= $h->t('No audit records for this tenant.') ?></div>
    <?php else: ?>
        <ol class="timeline card__body">
            <?php foreach ($history as $a): ?>
                <li><div><code><?= $h->e($a['action']) ?></code> <?= $h->badge($a['result']) ?></div>
                    <div class="small muted"><?= $h->datetime($a['occurred_at'], 'Y-m-d H:i:s') ?> · <?= $h->e($a['actor_name'] ?? ($a['actor_id'] ? substr((string) $a['actor_id'], 0, 8) : 'system')) ?></div></li>
            <?php endforeach ?>
        </ol>
    <?php endif ?>
</div>
