<?php

declare(strict_types=1);

/**
 * Read-only roles × permissions matrix (effective permissions, including inheritance).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<string> $roles
 * @var array<string,array<string,string>> $groups  group => [permission => description]
 * @var array<string,array<string,int>> $grid       role => flipped effective permissions
 */

$this->setTitle($h->tr('Permission matrix'));
$this->setParameter('breadcrumbs', [$h->tr('RBAC & Access Control') => $h->url('admin.rbac'), $h->tr('Permission matrix')]);
?>
<div class="page-head"><div class="page-head__titles">
    <h1><?= $h->t('Permission matrix') ?></h1>
    <p class="page-head__sub"><?= $h->t('Effective permissions per role, including inheritance. Edit a role to change its permissions.') ?></p>
</div></div>

<?= $this->render('./_tabs', ['active' => 'matrix']) ?>

<div class="card">
    <div class="table-wrap id-matrix"><table class="table table--compact">
        <caption class="sr-only"><?= $h->t('Roles by permissions') ?></caption>
        <thead><tr>
            <th scope="col"><?= $h->t('Permission') ?></th>
            <?php foreach ($roles as $r): ?><th scope="col" class="id-matrix__role"><a href="<?= $h->url('admin.rbac.role', ['role' => $r]) ?>"><?= $h->e($r) ?></a></th><?php endforeach ?>
        </tr></thead>
        <tbody>
        <?php foreach ($groups as $group => $perms): ?>
            <tr class="id-matrix__group"><th scope="rowgroup" colspan="<?= count($roles) + 1 ?>"><?= $h->e($group) ?></th></tr>
            <?php foreach ($perms as $perm => $desc): ?>
                <tr>
                    <th scope="row" class="id-matrix__perm"><code><?= $h->e($perm) ?></code><div class="small muted"><?= $h->e($desc) ?></div></th>
                    <?php foreach ($roles as $r): ?>
                        <td class="id-matrix__cell"><?= isset($grid[$r][$perm])
                            ? '<span class="id-yes" title="' . $h->e($r . ': ' . $perm) . '">' . $h->icon('check') . '<span class="sr-only">' . $h->t('granted') . '</span></span>'
                            : '<span class="muted" aria-hidden="true">·</span><span class="sr-only">' . $h->t('not granted') . '</span>' ?></td>
                    <?php endforeach ?>
                </tr>
            <?php endforeach ?>
        <?php endforeach ?>
        </tbody>
    </table></div>
</div>
