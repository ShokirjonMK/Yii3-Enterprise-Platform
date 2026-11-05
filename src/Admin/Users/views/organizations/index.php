<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $tree
 * @var array<string,string> $filters
 * @var bool $cross
 * @var array<string,string> $tenants
 * @var list<string> $statuses
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Organizations'));
$this->setParameter('breadcrumbs', [$h->tr('Users') => $h->url('admin.users'), $h->tr('Organizations')]);
$canManage = $can('users.manage');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Organizations') ?></h1>
        <p class="page-head__sub"><?= $h->t('Organizational structure of {tenant}', ['tenant' => $tenants[$filters['tenant_id']] ?? '—']) ?></p>
    </div>
    <?php if ($canManage && $filters['tenant_id'] !== ''): ?>
        <div class="page-head__actions">
            <a class="btn btn--primary" href="<?= $h->url('admin.organizations.create', [], ['tenant_id' => $filters['tenant_id']]) ?>"><?= $h->icon('plus') ?> <?= $h->t('New organization') ?></a>
        </div>
    <?php endif ?>
</div>

<?= $this->render($h->view('@admin/Users/views/_tabs'), ['active' => 'organizations']) ?>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.organizations') ?>" role="search"
          hx-get="<?= $h->url('admin.organizations') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"
          hx-trigger="change, keyup changed delay:400ms from:find input[type=search], submit">
        <?php if ($cross): ?>
            <label class="sr-only" for="o-tenant"><?= $h->t('Tenant') ?></label>
            <select id="o-tenant" name="tenant_id">
                <?php foreach ($tenants as $id => $label): ?>
                    <option value="<?= $h->e($id) ?>"<?= $filters['tenant_id'] === $id ? ' selected' : '' ?>><?= $h->e($label) ?></option>
                <?php endforeach ?>
            </select>
        <?php endif ?>
        <label class="sr-only" for="o-q"><?= $h->t('Search') ?></label>
        <input id="o-q" type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Name or code') ?>">
        <label class="sr-only" for="o-status"><?= $h->t('Status') ?></label>
        <select id="o-status" name="status">
            <option value=""><?= $h->t('All statuses') ?></option>
            <?php foreach ($statuses as $s): ?><option value="<?= $h->e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?>
        </select>
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>

    <?php if ($tree === []): ?>
        <div class="empty"><?= $h->icon('building') ?><p><?= $filters['q'] !== '' || $filters['status'] !== '' ? $h->t('No organizations match the filter.') : $h->t('This tenant has no organizations yet.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr>
                <th scope="col"><?= $h->t('Name') ?></th><th scope="col"><?= $h->t('Code') ?></th><th scope="col"><?= $h->t('Type') ?></th>
                <th scope="col"><?= $h->t('INN') ?></th><th scope="col" class="num"><?= $h->t('Users') ?></th><th scope="col"><?= $h->t('Status') ?></th>
                <th scope="col"><span class="sr-only"><?= $h->t('Actions') ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($tree as $o): ?>
                <tr>
                    <td><span class="id-tree id-tree--<?= min(8, (int) $o['depth']) ?>"><?= (int) $o['depth'] > 0 ? '<span class="muted" aria-hidden="true">└ </span>' : '' ?><?= $h->e($o['name']) ?></span></td>
                    <td class="mono small"><?= $h->e($o['code']) ?></td>
                    <td class="small"><?= $h->t($o['type']) ?></td>
                    <td class="mono small"><?= $h->e($o['inn'] ?? '—') ?></td>
                    <td class="num"><a href="<?= $h->url('admin.users', [], ['organization_id' => $o['id'], 'tenant_id' => $o['tenant_id']]) ?>"><?= (int) $o['user_count'] ?></a></td>
                    <td><?= $h->badge($o['status']) ?></td>
                    <td class="actions">
                        <?php if ($canManage): ?>
                            <a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.organizations.create', [], ['tenant_id' => $o['tenant_id'], 'parent_id' => $o['id']]) ?>" aria-label="<?= $h->t('Add child unit to {n}', ['n' => $o['name']]) ?>"><?= $h->icon('plus') ?></a>
                            <a class="btn btn--sm btn--ghost" href="<?= $h->url('admin.organizations.edit', ['id' => $o['id']]) ?>" aria-label="<?= $h->t('Edit {n}', ['n' => $o['name']]) ?>"><?= $h->icon('edit') ?></a>
                            <?php if ((int) $o['child_count'] === 0 && (int) $o['user_count'] === 0): ?>
                                <form method="post" action="<?= $h->url('admin.organizations.delete', ['id' => $o['id']]) ?>" class="id-inline" data-confirm="<?= $h->t('Delete organization {n}?', ['n' => $o['name']]) ?>">
                                    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                                    <button class="btn btn--sm btn--ghost" type="submit" aria-label="<?= $h->t('Delete {n}', ['n' => $o['name']]) ?>"><?= $h->icon('trash') ?></button>
                                </form>
                            <?php endif ?>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
</div>
