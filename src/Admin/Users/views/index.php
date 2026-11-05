<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var bool $cross
 * @var array<string,string> $tenants
 * @var array<string,string> $organizations
 * @var list<string> $roles
 * @var list<string> $statuses
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Users & Organizations'));
$this->setParameter('breadcrumbs', [$h->tr('Users')]);
$query = array_filter($filters, static fn($v) => $v !== '');
$sort = $page->sort;
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Users & Organizations') ?></h1>
        <p class="page-head__sub"><?= $h->t('{n} users match the current filter', ['n' => $page->total]) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('admin.organizations') ?>"><?= $h->icon('building') ?> <?= $h->t('Organizations') ?></a>
        <?php if ($can('users.manage')): ?>
            <a class="btn btn--primary" href="<?= $h->url('admin.users.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New user') ?></a>
        <?php endif ?>
    </div>
</div>

<?= $this->render('./_tabs', ['active' => 'users']) ?>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.users') ?>" role="search"
          hx-get="<?= $h->url('admin.users') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"
          hx-trigger="change, keyup changed delay:400ms from:find input[type=search], submit">
        <label class="sr-only" for="f-q"><?= $h->t('Search') ?></label>
        <input id="f-q" type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Username, name or e-mail') ?>">
        <label class="sr-only" for="f-status"><?= $h->t('Status') ?></label>
        <select id="f-status" name="status">
            <option value=""><?= $h->t('All statuses') ?></option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= $h->e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option>
            <?php endforeach ?>
        </select>
        <label class="sr-only" for="f-role"><?= $h->t('Role') ?></label>
        <select id="f-role" name="role">
            <option value=""><?= $h->t('All roles') ?></option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $h->e($r) ?>"<?= $filters['role'] === $r ? ' selected' : '' ?>><?= $h->e($r) ?></option>
            <?php endforeach ?>
        </select>
        <?php if ($cross): ?>
            <label class="sr-only" for="f-tenant"><?= $h->t('Tenant') ?></label>
            <select id="f-tenant" name="tenant_id">
                <option value=""><?= $h->t('All tenants') ?></option>
                <?php foreach ($tenants as $id => $label): ?>
                    <option value="<?= $h->e($id) ?>"<?= $filters['tenant_id'] === $id ? ' selected' : '' ?>><?= $h->e($label) ?></option>
                <?php endforeach ?>
            </select>
        <?php endif ?>
        <?php if ($organizations !== []): ?>
            <label class="sr-only" for="f-org"><?= $h->t('Organization') ?></label>
            <select id="f-org" name="organization_id">
                <option value=""><?= $h->t('All organizations') ?></option>
                <?php foreach ($organizations as $id => $label): ?>
                    <option value="<?= $h->e($id) ?>"<?= $filters['organization_id'] === $id ? ' selected' : '' ?>><?= $h->e($label) ?></option>
                <?php endforeach ?>
            </select>
        <?php endif ?>
        <input type="hidden" name="sort" value="<?= $h->e($filters['sort']) ?>">
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
        <?php if (array_diff_key($query, ['sort' => 1]) !== []): ?>
            <a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.users') ?>"><?= $h->t('Reset') ?></a>
        <?php endif ?>
    </form>

    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('users') ?><p><?= $h->t('No users found.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
            <table class="table">
                <thead><tr>
                    <th scope="col"><?= $h->sortLink($h->tr('Username'), 'username', $sort, $query, 'admin.users') ?></th>
                    <th scope="col"><?= $h->sortLink($h->tr('Name'), 'full_name', $sort, $query, 'admin.users') ?></th>
                    <th scope="col"><?= $h->sortLink($h->tr('E-mail'), 'email', $sort, $query, 'admin.users') ?></th>
                    <th scope="col"><?= $h->t('Roles') ?></th>
                    <?php if ($cross): ?><th scope="col"><?= $h->t('Tenant') ?></th><?php endif ?>
                    <th scope="col"><?= $h->t('Organization') ?></th>
                    <th scope="col"><?= $h->sortLink($h->tr('Status'), 'status', $sort, $query, 'admin.users') ?></th>
                    <th scope="col"><?= $h->sortLink($h->tr('Last sign-in'), 'last_login_at', $sort, $query, 'admin.users') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($page->items as $u): ?>
                    <tr>
                        <td><a href="<?= $h->url('admin.users.view', ['id' => $u['id']]) ?>" class="mono"><?= $h->e($u['username']) ?></a></td>
                        <td><?= $h->e($u['full_name']) ?></td>
                        <td class="small"><?= $h->e($u['email']) ?></td>
                        <td><?php foreach (array_filter(explode(',', (string) $u['roles'])) as $r): ?><span class="tag"><?= $h->e($r) ?></span><?php endforeach ?></td>
                        <?php if ($cross): ?><td class="small"><?= $h->e($u['tenant_name'] ?? '—') ?></td><?php endif ?>
                        <td class="small"><?= $h->e($u['organization_name'] ?? '—') ?></td>
                        <td><?= $h->badge($u['status']) ?><?= !empty($u['mfa_enabled']) && $u['mfa_enabled'] !== 'f' ? ' <span class="tag" title="MFA">MFA</span>' : '' ?></td>
                        <td class="small nowrap"><?= $h->ago($u['last_login_at']) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.users', 'query' => $query]) ?>
</div>
