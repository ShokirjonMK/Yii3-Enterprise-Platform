<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,array{name:string, description:string, system:bool, admin:bool, flagged:bool, permissions:list<string>, children:list<string>, effective:int, users:int}> $roles
 * @var int $permissionCount
 * @var array{missing_permissions:int, missing_roles:int} $drift
 * @var bool $isSuper
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('RBAC & Access Control'));
$this->setParameter('breadcrumbs', [$h->tr('RBAC & Access Control')]);
$needsSync = $drift['missing_permissions'] + $drift['missing_roles'] > 0;
$custom = count(array_filter($roles, static fn(array $r) => !$r['system']));
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('RBAC & Access Control') ?></h1>
        <p class="page-head__sub"><?= $h->t('{r} roles ({c} custom) · {p} permissions', ['r' => count($roles), 'c' => $custom, 'p' => $permissionCount]) ?></p>
    </div>
    <?php if ($can('rbac.manage')): ?>
        <div class="page-head__actions">
            <form method="post" action="<?= $h->url('admin.rbac.sync') ?>" data-confirm="<?= $h->t('Synchronize the RBAC storage with the permission catalog? Assignments and admin-panel edits are kept.') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <button class="btn<?= $needsSync ? ' btn--primary' : '' ?>" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Sync catalog') ?></button>
            </form>
        </div>
    <?php endif ?>
</div>

<?php if ($needsSync): ?>
    <div class="alert alert--warning" role="status"><?= $h->icon('alert') ?> <span><?= $h->t('The RBAC storage is behind the catalog: {p} permission(s) and {r} role(s) missing. Run "Sync catalog".', ['p' => $drift['missing_permissions'], 'r' => $drift['missing_roles']]) ?></span></div>
<?php endif ?>

<?= $this->render('./_tabs', ['active' => 'roles']) ?>

<div class="grid<?= $can('rbac.manage') ? ' grid--sidebar' : '' ?>">
<div class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th scope="col"><?= $h->t('Role') ?></th><th scope="col"><?= $h->t('Description') ?></th><th scope="col"><?= $h->t('Inherits') ?></th>
            <th scope="col" class="num"><?= $h->t('Effective permissions') ?></th><th scope="col" class="num"><?= $h->t('Users') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($roles as $name => $r): ?>
            <tr>
                <td>
                    <a class="mono" href="<?= $h->url('admin.rbac.role', ['role' => $name]) ?>"><?= $h->e($name) ?></a>
                    <?= $r['system'] ? $h->badge('info', $h->tr('system')) : $h->badge('success', $h->tr('custom')) ?>
                    <?= $r['admin'] ? ' ' . $h->badge('warning', $h->tr('admin')) : '' ?>
                </td>
                <td class="small"><?= $h->e($r['description']) ?></td>
                <td><?php foreach ($r['children'] as $c): ?><span class="tag"><?= $h->e($c) ?></span><?php endforeach ?></td>
                <td class="num"><?= (int) $r['effective'] ?></td>
                <td class="num"><a href="<?= $h->url('admin.rbac.role', ['role' => $name]) ?>"><?= (int) $r['users'] ?></a></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <div class="card__body small muted"><?= $h->t('Administrative roles (catalog admin roles, roles flagged administrative or holding a privileged permission) can be granted, revoked or edited only by a superadmin. Users cannot change their own roles, and the last active superadmin cannot lose the role.') ?></div>
</div>

<?php if ($can('rbac.manage')): ?>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('New role') ?></h2></div>
        <form class="card__body form" method="post" action="<?= $h->url('admin.rbac.roles.create') ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="field">
                <label for="nr-name"><?= $h->t('Name') ?></label>
                <input id="nr-name" name="name" required pattern="[a-z][a-z0-9_]{1,63}" maxlength="64" placeholder="report_viewer" autocomplete="off">
                <div class="field__hint"><?= $h->t('Lowercase latin letters, digits and "_".') ?></div>
            </div>
            <div class="field">
                <label for="nr-desc"><?= $h->t('Description') ?></label>
                <input id="nr-desc" name="description" maxlength="500">
            </div>
            <div class="field">
                <label for="nr-clone"><?= $h->t('Copy permissions from') ?></label>
                <select id="nr-clone" name="clone_from">
                    <option value=""><?= $h->t('— empty role —') ?></option>
                    <?php foreach ($roles as $name => $r): ?><option value="<?= $h->e($name) ?>"><?= $h->e($name) ?></option><?php endforeach ?>
                </select>
            </div>
            <?php if ($isSuper): ?>
                <label class="check"><input type="checkbox" name="is_admin" value="1"> <?= $h->t('Administrative role (only superadmins can grant it)') ?></label>
            <?php endif ?>
            <div><button class="btn btn--primary" type="submit"><?= $h->icon('plus') ?> <?= $h->t('Create role') ?></button></div>
        </form>
    </div>
<?php endif ?>
</div>
