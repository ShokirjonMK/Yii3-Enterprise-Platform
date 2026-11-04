<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var array{active:int, suspended:int, archived:int, total:int} $counts
 * @var list<string> $statuses
 * @var bool $mayCreate
 */

$this->setTitle($h->tr('Multi-Tenancy'));
$this->setParameter('breadcrumbs', [$h->tr('Multi-Tenancy')]);
$query = array_filter($filters, static fn($v) => $v !== '');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Multi-Tenancy') ?></h1>
        <p class="page-head__sub"><?= $h->t('Tenants are isolated organizations sharing the platform') ?></p>
    </div>
    <?php if ($mayCreate): ?>
        <div class="page-head__actions"><a class="btn btn--primary" href="<?= $h->url('admin.tenants.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New tenant') ?></a></div>
    <?php endif ?>
</div>

<div class="grid grid--4 mb">
    <?php foreach (['total' => 'Total', 'active' => 'Active', 'suspended' => 'Suspended', 'archived' => 'Archived'] as $k => $label): ?>
        <div class="card kpi"><div class="kpi__label"><?= $h->icon('building') ?> <?= $h->t($label) ?></div><div class="kpi__value"><?= (int) $counts[$k] ?></div></div>
    <?php endforeach ?>
</div>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.tenants') ?>" role="search"
          hx-get="<?= $h->url('admin.tenants') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"
          hx-trigger="change, keyup changed delay:400ms from:find input[type=search], submit">
        <label class="sr-only" for="t-q"><?= $h->t('Search') ?></label>
        <input id="t-q" type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Code, name or domain') ?>">
        <label class="sr-only" for="t-status"><?= $h->t('Status') ?></label>
        <select id="t-status" name="status">
            <option value=""><?= $h->t('All statuses') ?></option>
            <?php foreach ($statuses as $s): ?><option value="<?= $h->e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?>
        </select>
        <input type="hidden" name="sort" value="<?= $h->e($filters['sort']) ?>">
        <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('building') ?><p><?= $h->t('No tenants found.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML"><table class="table">
            <thead><tr>
                <th scope="col"><?= $h->sortLink($h->tr('Code'), 'code', $page->sort, $query, 'admin.tenants') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Name'), 'name', $page->sort, $query, 'admin.tenants') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Domain'), 'domain', $page->sort, $query, 'admin.tenants') ?></th>
                <th scope="col" class="num"><?= $h->sortLink($h->tr('Users'), 'users', $page->sort, $query, 'admin.tenants') ?></th>
                <th scope="col" class="num"><?= $h->t('Organizations') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Status'), 'status', $page->sort, $query, 'admin.tenants') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Created'), 'created_at', $page->sort, $query, 'admin.tenants') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $t): ?>
                <tr>
                    <td><a class="mono" href="<?= $h->url('admin.tenants.view', ['id' => $t['id']]) ?>"><?= $h->e($t['code']) ?></a></td>
                    <td><?= $h->e($t['name']) ?></td>
                    <td class="small mono"><?= $h->e($t['domain'] ?? '—') ?></td>
                    <td class="num"><?= (int) $t['user_count'] ?> <span class="small muted">(<?= (int) $t['active_user_count'] ?> <?= $h->t('active') ?>)</span></td>
                    <td class="num"><?= (int) $t['organization_count'] ?></td>
                    <td><?= $h->badge($t['status'] === 'suspended' ? 'paused' : $t['status'], $h->tr($t['status'])) ?></td>
                    <td class="small nowrap"><?= $h->datetime($t['created_at'], 'Y-m-d') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.tenants', 'query' => $query]) ?>
</div>
