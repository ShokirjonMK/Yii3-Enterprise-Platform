<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $name
 * @var array{name:string, description:string, system:bool, admin:bool, flagged:bool, permissions:list<string>, children:list<string>, effective:int} $role
 * @var list<string> $direct
 * @var array<string,list<string>> $grouped
 * @var array<string,string> $descriptions
 * @var array<string,array<string,array{description:string, system:bool, privileged:bool, roles:list<string>}>> $allGroups
 * @var array<string,int> $inherited  permissions granted only through child roles
 * @var list<string> $allRoles
 * @var list<array{child:string, op:string}> $overrides
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var bool $isAdminRole
 * @var bool $isSuper
 * @var bool $mayAssign
 * @var bool $mayEdit
 * @var bool $mayDelete
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Role {r}', ['r' => $name]));
$this->setParameter('breadcrumbs', [$h->tr('RBAC & Access Control') => $h->url('admin.rbac'), $name]);
$query = array_filter($filters, static fn($v) => $v !== '');
$csrfInput = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$directSet = array_flip($role['permissions']);
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1 class="mono"><?= $h->e($name) ?>
            <?= $role['system'] ? $h->badge('info', $h->tr('system')) : $h->badge('success', $h->tr('custom')) ?>
            <?= $isAdminRole ? $h->badge('warning', $h->tr('administrative')) : '' ?></h1>
        <p class="page-head__sub"><?= $h->e($role['description']) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($mayEdit && $role['system'] && $overrides !== []): ?>
            <form method="post" action="<?= $h->url('admin.rbac.roles.reset', ['role' => $name]) ?>" data-confirm="<?= $h->t('Discard all admin-panel changes of role {r} and restore the catalog definition?', ['r' => $name]) ?>">
                <?= $csrfInput ?><button class="btn" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Reset to catalog defaults') ?></button>
            </form>
        <?php endif ?>
        <?php if ($mayDelete): ?>
            <form method="post" action="<?= $h->url('admin.rbac.roles.delete', ['role' => $name]) ?>" data-confirm="<?= $h->t('Delete role {r}? It will be removed from {n} user(s).', ['r' => $name, 'n' => (int) $page->total]) ?>">
                <?= $csrfInput ?><button class="btn btn--danger" type="submit"><?= $h->icon('trash') ?> <?= $h->t('Delete role') ?></button>
            </form>
        <?php endif ?>
    </div>
</div>

<?= $this->render('./_tabs', ['active' => 'roles']) ?>

<div class="grid grid--sidebar">
    <div class="stack">
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Users with this role') ?></h2><span class="small muted"><?= (int) $page->total ?></span></div>
        <form class="toolbar" method="get" action="<?= $h->url('admin.rbac.role', ['role' => $name]) ?>" role="search"
              hx-get="<?= $h->url('admin.rbac.role', ['role' => $name]) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"
              hx-trigger="keyup changed delay:400ms from:find input[type=search], submit">
            <label class="sr-only" for="r-q"><?= $h->t('Search') ?></label>
            <input id="r-q" type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Username or name') ?>">
            <input type="hidden" name="sort" value="<?= $h->e($filters['sort']) ?>">
            <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
        </form>
        <?php if ($page->items === []): ?>
            <div class="empty"><?= $h->t('No users hold this role directly.') ?></div>
        <?php else: ?>
            <div class="table-wrap" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML"><table class="table table--compact">
                <thead><tr>
                    <th scope="col"><?= $h->sortLink($h->tr('Username'), 'username', $page->sort, $query, 'admin.rbac.role', ['role' => $name]) ?></th>
                    <th scope="col"><?= $h->sortLink($h->tr('Name'), 'full_name', $page->sort, $query, 'admin.rbac.role', ['role' => $name]) ?></th>
                    <th scope="col"><?= $h->t('Tenant') ?></th>
                    <th scope="col"><?= $h->sortLink($h->tr('Status'), 'status', $page->sort, $query, 'admin.rbac.role', ['role' => $name]) ?></th>
                    <th scope="col"><?= $h->sortLink($h->tr('Assigned'), 'assigned_at', $page->sort, $query, 'admin.rbac.role', ['role' => $name]) ?></th>
                    <th scope="col"><span class="sr-only"><?= $h->t('Actions') ?></span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($page->items as $u): ?>
                    <tr>
                        <td><a class="mono" href="<?= $h->url('admin.users.view', ['id' => $u['id']]) ?>"><?= $h->e($u['username']) ?></a></td>
                        <td><?= $h->e($u['full_name']) ?></td>
                        <td class="small"><?= $h->e($u['tenant_name'] ?? '—') ?></td>
                        <td><?= $h->badge($u['status']) ?></td>
                        <td class="small nowrap"><?= $h->datetime((new DateTimeImmutable())->setTimestamp((int) $u['assigned_at'])) ?></td>
                        <td class="actions">
                            <?php if ($mayAssign): ?>
                                <form method="post" action="<?= $h->url('admin.rbac.revoke', ['role' => $name]) ?>" data-confirm="<?= $h->t('Revoke role {r} from {u}?', ['r' => $name, 'u' => $u['username']]) ?>">
                                    <?= $csrfInput ?><input type="hidden" name="user_id" value="<?= $h->e($u['id']) ?>">
                                    <button class="btn btn--sm btn--danger" type="submit"><?= $h->t('Revoke') ?></button>
                                </form>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        <?php endif ?>
        <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.rbac.role', 'args' => ['role' => $name], 'query' => $query]) ?>
        <?php if ($mayAssign): ?>
            <form class="card__foot" method="post" action="<?= $h->url('admin.rbac.assign', ['role' => $name]) ?>">
                <?= $csrfInput ?>
                <label class="sr-only" for="r-assign"><?= $h->t('Username or e-mail') ?></label>
                <input id="r-assign" type="text" name="username" required placeholder="<?= $h->t('Username or e-mail') ?>" autocomplete="off">
                <button class="btn btn--primary" type="submit"><?= $h->icon('plus') ?> <?= $h->t('Assign role') ?></button>
            </form>
        <?php elseif ($isAdminRole && $can('rbac.manage')): ?>
            <div class="card__foot small muted"><?= $h->t('Only a superadmin can assign, revoke or edit this role.') ?></div>
        <?php endif ?>
    </div>

    <?php if ($mayEdit): ?>
    <form class="card" method="post" action="<?= $h->url('admin.rbac.roles.update', ['role' => $name]) ?>">
        <?= $csrfInput ?>
        <div class="card__head"><h2><?= $h->t('Edit role') ?></h2><button class="btn btn--primary btn--sm" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save') ?></button></div>
        <div class="card__body form">
            <?php if (!$role['system']): ?>
                <div class="field">
                    <label for="er-desc"><?= $h->t('Description') ?></label>
                    <input id="er-desc" name="description" maxlength="500" value="<?= $h->e($role['description']) ?>">
                </div>
            <?php else: ?>
                <p class="small muted"><?= $h->t('System role: the description comes from the catalog. Permission and inheritance changes are kept as overrides and survive "Sync catalog".') ?></p>
            <?php endif ?>
            <?php if ($isSuper): ?>
                <input type="hidden" name="is_admin_present" value="1">
                <label class="check"><input type="checkbox" name="is_admin" value="1"<?= $role['flagged'] ? ' checked' : '' ?>> <?= $h->t('Administrative role (only superadmins can grant it)') ?></label>
            <?php endif ?>

            <fieldset class="rbac-checks">
                <legend><?= $h->t('Inherits from (child roles)') ?></legend>
                <?php foreach ($allRoles as $r): ?>
                    <label class="check"><input type="checkbox" name="children[]" value="<?= $h->e($r) ?>"<?= in_array($r, $role['children'], true) ? ' checked' : '' ?>> <span class="mono"><?= $h->e($r) ?></span></label>
                <?php endforeach ?>
            </fieldset>

            <p class="small muted"><?= $h->t('Checked = granted directly. "inherited" = already granted through a child role. "All" grants the whole group.') ?></p>
            <?php foreach ($allGroups as $group => $perms): ?>
                <fieldset class="rbac-checks">
                    <legend><span class="mono"><?= $h->e($group) ?></span>
                        <label class="check small"><input type="checkbox" name="groups[]" value="<?= $h->e($group) ?>"> <?= $h->t('All') ?></label></legend>
                    <?php foreach ($perms as $p => $def): ?>
                        <label class="check rbac-perm">
                            <input type="checkbox" name="permissions[]" value="<?= $h->e($p) ?>"<?= isset($directSet[$p]) ? ' checked' : '' ?>>
                            <span><code><?= $h->e($p) ?></code>
                                <?= $def['privileged'] ? $h->badge('warning', $h->tr('privileged')) : '' ?>
                                <?= !$def['system'] ? $h->badge('success', $h->tr('custom')) : '' ?>
                                <?= isset($inherited[$p]) ? $h->badge('info', $h->tr('inherited')) : '' ?>
                                <span class="small muted"><?= $h->e($def['description']) ?></span></span>
                        </label>
                    <?php endforeach ?>
                </fieldset>
            <?php endforeach ?>
        </div>
        <div class="card__foot"><button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save') ?></button></div>
    </form>
    <?php endif ?>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Definition') ?></h2></div>
            <div class="card__body">
                <p class="label"><?= $h->t('Inherits from') ?></p>
                <p><?php if ($role['children'] === []): ?><span class="muted">—</span><?php endif ?><?php foreach ($role['children'] as $c): ?><a class="tag" href="<?= $h->url('admin.rbac.role', ['role' => $c]) ?>"><?= $h->e($c) ?></a><?php endforeach ?></p>
                <p class="label"><?= $h->t('Directly granted') ?></p>
                <p><?php if ($direct === []): ?><span class="muted">—</span><?php endif ?><?php foreach ($direct as $p): ?><span class="tag"><?= $h->e($p) ?></span><?php endforeach ?></p>
                <?php if ($overrides !== []): ?>
                    <p class="label"><?= $h->t('Changes against the catalog') ?></p>
                    <p><?php foreach ($overrides as $o): ?><span class="tag"><?= $o['op'] === 'add' ? '+' : '−' ?> <?= $h->e($o['child']) ?></span><?php endforeach ?></p>
                <?php endif ?>
            </div>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Effective permissions') ?></h2><span class="small muted"><?= array_sum(array_map('count', $grouped)) ?></span></div>
            <div class="card__body">
                <?php foreach ($grouped as $group => $perms): ?>
                    <p class="label"><?= $h->e($group) ?></p>
                    <ul class="id-perms">
                        <?php foreach ($perms as $p): ?><li><code><?= $h->e($p) ?></code> <span class="small muted"><?= $h->e($descriptions[$p] ?? '') ?></span></li><?php endforeach ?>
                    </ul>
                <?php endforeach ?>
            </div>
        </div>
    </div>
</div>
