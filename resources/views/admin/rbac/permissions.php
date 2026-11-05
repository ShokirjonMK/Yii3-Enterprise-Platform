<?php

declare(strict_types=1);

/**
 * All permissions in the RBAC storage (catalog = system, admin panel = custom) and the roles that grant them directly.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,array<string,array{description:string, system:bool, privileged:bool, roles:list<string>}>> $groups
 * @var bool $manage
 * @var string $csrf
 */

$this->setTitle($h->tr('Permissions'));
$this->setParameter('breadcrumbs', [$h->tr('RBAC & Access Control') => $h->url('admin.rbac'), $h->tr('Permissions')]);
$total = array_sum(array_map('count', $groups));
?>
<div class="page-head"><div class="page-head__titles">
    <h1><?= $h->t('Permissions') ?></h1>
    <p class="page-head__sub"><?= $h->t('{n} permissions. System permissions are checked by the platform code; custom permissions are for business modules and integrations.', ['n' => $total]) ?></p>
</div></div>

<?= $this->render('./_tabs', ['active' => 'permissions']) ?>

<div class="grid<?= $manage ? ' grid--sidebar' : '' ?>">
    <div class="card">
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr>
                <th scope="col"><?= $h->t('Permission') ?></th><th scope="col"><?= $h->t('Description') ?></th>
                <th scope="col"><?= $h->t('Granted directly by') ?></th><th scope="col"><span class="sr-only"><?= $h->t('Actions') ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($groups as $group => $perms): ?>
                <tr class="id-matrix__group"><th scope="rowgroup" colspan="4"><?= $h->e($group) ?></th></tr>
                <?php foreach ($perms as $p => $def): ?>
                    <tr>
                        <td class="nowrap"><code><?= $h->e($p) ?></code>
                            <?= $def['system'] ? '' : ' ' . $h->badge('success', $h->tr('custom')) ?>
                            <?= $def['privileged'] ? ' ' . $h->badge('warning', $h->tr('privileged')) : '' ?></td>
                        <td class="small"><?= $h->e($def['description']) ?></td>
                        <td><?php foreach ($def['roles'] as $r): ?><a class="tag" href="<?= $h->url('admin.rbac.role', ['role' => $r]) ?>"><?= $h->e($r) ?></a><?php endforeach ?></td>
                        <td class="actions"><?php if ($manage && !$def['system']): ?>
                            <form method="post" action="<?= $h->url('admin.rbac.permissions.delete', ['perm' => $p]) ?>" data-confirm="<?= $h->t('Delete permission {p}? It is removed from every role.', ['p' => $p]) ?>">
                                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button class="btn btn--sm btn--danger" type="submit"><?= $h->t('Delete') ?></button>
                            </form>
                        <?php endif ?></td>
                    </tr>
                <?php endforeach ?>
            <?php endforeach ?>
            </tbody>
        </table></div>
    </div>

    <?php if ($manage): ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('New permission') ?></h2></div>
            <form class="card__body form" method="post" action="<?= $h->url('admin.rbac.permissions.create') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <div class="field">
                    <label for="np-name"><?= $h->t('Name') ?></label>
                    <input id="np-name" name="name" required maxlength="126" pattern="[a-z][a-z0-9_]*(\.[a-z0-9_]+)+" placeholder="reports.export" autocomplete="off">
                    <div class="field__hint"><?= $h->t('Dotted lowercase name: module.action') ?></div>
                </div>
                <div class="field">
                    <label for="np-desc"><?= $h->t('Description') ?></label>
                    <input id="np-desc" name="description" required maxlength="500">
                </div>
                <p class="small muted"><?= $h->t('A new permission is granted to superadmin automatically; attach it to other roles on the role page.') ?></p>
                <div><button class="btn btn--primary" type="submit"><?= $h->icon('plus') ?> <?= $h->t('Create permission') ?></button></div>
            </form>
        </div>
    <?php endif ?>
</div>
