<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,array{description:string, permissions:list<string>, children:list<string>, effective:int, users:int, admin:bool}> $roles
 * @var int $permissionCount
 * @var array{missing_permissions:int, missing_roles:int} $drift
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('RBAC & Access Control'));
$this->setParameter('breadcrumbs', [$h->tr('RBAC & Access Control')]);
$needsSync = $drift['missing_permissions'] + $drift['missing_roles'] > 0;
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('RBAC & Access Control') ?></h1>
        <p class="page-head__sub"><?= $h->t('{r} roles · {p} permissions (catalog: config/common/permissions)', ['r' => count($roles), 'p' => $permissionCount]) ?></p>
    </div>
    <?php if ($can('rbac.manage')): ?>
        <div class="page-head__actions">
            <form method="post" action="<?= $h->url('admin.rbac.sync') ?>" data-confirm="<?= $h->t('Synchronize the RBAC storage with the permission catalog? Assignments are never deleted.') ?>">
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

<div class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th scope="col"><?= $h->t('Role') ?></th><th scope="col"><?= $h->t('Description') ?></th><th scope="col"><?= $h->t('Inherits') ?></th>
            <th scope="col" class="num"><?= $h->t('Effective permissions') ?></th><th scope="col" class="num"><?= $h->t('Users') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($roles as $name => $r): ?>
            <tr>
                <td><a class="mono" href="<?= $h->url('admin.rbac.role', ['role' => $name]) ?>"><?= $h->e($name) ?></a><?= $r['admin'] ? ' ' . $h->badge('warning', $h->tr('admin')) : '' ?></td>
                <td class="small"><?= $h->e($r['description']) ?></td>
                <td><?php foreach ($r['children'] as $c): ?><span class="tag"><?= $h->e($c) ?></span><?php endforeach ?></td>
                <td class="num"><?= (int) $r['effective'] ?></td>
                <td class="num"><a href="<?= $h->url('admin.rbac.role', ['role' => $name]) ?>"><?= (int) $r['users'] ?></a></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <div class="card__body small muted"><?= $h->t('Administrative roles can be granted or revoked only by a superadmin. Users cannot change their own roles, and the last active superadmin cannot lose the role.') ?></div>
</div>
