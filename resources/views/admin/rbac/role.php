<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $name
 * @var array{description:string, permissions:list<string>, children:list<string>} $role
 * @var list<string> $direct
 * @var array<string,list<string>> $grouped
 * @var array<string,string> $descriptions
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var bool $isAdminRole
 * @var bool $mayAssign
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Role {r}', ['r' => $name]));
$this->setParameter('breadcrumbs', [$h->tr('RBAC & Access Control') => $h->url('admin.rbac'), $name]);
$query = array_filter($filters, static fn($v) => $v !== '');
$csrfInput = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
?>
<div class="page-head"><div class="page-head__titles">
    <h1 class="mono"><?= $h->e($name) ?> <?= $isAdminRole ? $h->badge('warning', $h->tr('administrative')) : '' ?></h1>
    <p class="page-head__sub"><?= $h->e($role['description']) ?></p>
</div></div>

<div class="grid grid--sidebar">
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
            <div class="card__foot small muted"><?= $h->t('Only a superadmin can assign or revoke this role.') ?></div>
        <?php endif ?>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Definition') ?></h2></div>
            <div class="card__body">
                <p class="label"><?= $h->t('Inherits from') ?></p>
                <p><?php if ($role['children'] === []): ?><span class="muted">—</span><?php endif ?><?php foreach ($role['children'] as $c): ?><a class="tag" href="<?= $h->url('admin.rbac.role', ['role' => $c]) ?>"><?= $h->e($c) ?></a><?php endforeach ?></p>
                <p class="label"><?= $h->t('Directly granted') ?></p>
                <p><?php foreach ($direct as $p): ?><span class="tag"><?= $h->e($p) ?></span><?php endforeach ?></p>
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
