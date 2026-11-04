<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var array<string,int> $usage
 * @var bool $cross
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('API Management'));
$this->setParameter('breadcrumbs', [$h->tr('API Management')]);
$query = array_filter($filters, static fn($v) => $v !== '');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('API Management') ?></h1>
        <p class="page-head__sub"><?= $h->t('Machine clients (client credentials) and personal access tokens') ?></p>
    </div>
    <?php if ($can('api.manage')): ?>
        <div class="page-head__actions"><a class="btn btn--primary" href="<?= $h->url('admin.api.clients.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New API client') ?></a></div>
    <?php endif ?>
</div>

<?= $this->render('./_usage', ['usage' => $usage, 'active' => 'clients']) ?>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.api') ?>" role="search"
          hx-get="<?= $h->url('admin.api') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"
          hx-trigger="change, keyup changed delay:400ms from:find input[type=search], submit">
        <label class="sr-only" for="a-q"><?= $h->t('Search') ?></label>
        <input id="a-q" type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Name or client id') ?>">
        <label class="sr-only" for="a-status"><?= $h->t('Status') ?></label>
        <select id="a-status" name="status">
            <option value=""><?= $h->t('All') ?></option>
            <?php foreach (['enabled' => 'Enabled', 'disabled' => 'Disabled', 'expired' => 'Expired'] as $k => $l): ?>
                <option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $h->t($l) ?></option>
            <?php endforeach ?>
        </select>
        <input type="hidden" name="sort" value="<?= $h->e($filters['sort']) ?>">
        <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('api') ?><p><?= $h->t('No API clients yet.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML"><table class="table">
            <thead><tr>
                <th scope="col"><?= $h->sortLink($h->tr('Name'), 'name', $page->sort, $query, 'admin.api') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Client ID'), 'client_id', $page->sort, $query, 'admin.api') ?></th>
                <th scope="col"><?= $h->t('Scopes') ?></th>
                <?php if ($cross): ?><th scope="col"><?= $h->t('Tenant') ?></th><?php endif ?>
                <th scope="col" class="num"><?= $h->t('Rate / min') ?></th>
                <th scope="col"><?= $h->t('Status') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Last used'), 'last_used_at', $page->sort, $query, 'admin.api') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Expires'), 'expires_at', $page->sort, $query, 'admin.api') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $c): ?>
                <tr>
                    <td><a href="<?= $h->url('admin.api.clients.view', ['id' => $c['id']]) ?>"><?= $h->e($c['name']) ?></a></td>
                    <td class="mono small"><?= $h->e($c['client_id']) ?></td>
                    <td><?php foreach ($c['scopes'] as $s): ?><span class="tag"><?= $h->e($s) ?></span><?php endforeach ?></td>
                    <?php if ($cross): ?><td class="small"><?= $h->e($c['tenant_name'] ?? $h->tr('platform')) ?></td><?php endif ?>
                    <td class="num"><?= (int) $c['rate_limit_per_minute'] ?></td>
                    <td><?= $c['expired'] ? $h->badge('failed', $h->tr('expired')) : $h->badge($c['enabled'] ? 'enabled' : 'disabled') ?></td>
                    <td class="small nowrap"><?= $h->ago($c['last_used_at']) ?></td>
                    <td class="small nowrap"><?= $h->datetime($c['expires_at'], 'Y-m-d') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.api', 'query' => $query]) ?>
</div>
