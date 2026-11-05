<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var array<string,int> $usage
 * @var string $csrf
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Personal tokens'));
$this->setParameter('breadcrumbs', [$h->tr('API Management') => $h->url('admin.api'), $h->tr('Personal tokens')]);
$query = array_filter($filters, static fn($v) => $v !== '');
?>
<div class="page-head"><div class="page-head__titles">
    <h1><?= $h->t('Personal tokens') ?></h1>
    <p class="page-head__sub"><?= $h->t('Tokens users created for themselves (Account → Security). Token values are never stored — only SHA-256 hashes.') ?></p>
</div></div>

<?= $this->render('./_usage', ['usage' => $usage, 'active' => 'tokens']) ?>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.api.tokens') ?>" role="search"
          hx-get="<?= $h->url('admin.api.tokens') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"
          hx-trigger="change, keyup changed delay:400ms from:find input[type=search], submit">
        <label class="sr-only" for="k-q"><?= $h->t('Search') ?></label>
        <input id="k-q" type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Token name or user') ?>">
        <label class="sr-only" for="k-status"><?= $h->t('Status') ?></label>
        <select id="k-status" name="status">
            <option value=""><?= $h->t('All') ?></option>
            <?php foreach (['active' => 'Active', 'expired' => 'Expired', 'revoked' => 'Revoked'] as $k => $l): ?>
                <option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $h->t($l) ?></option>
            <?php endforeach ?>
        </select>
        <input type="hidden" name="sort" value="<?= $h->e($filters['sort']) ?>">
        <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('key') ?><p><?= $h->t('No personal tokens found.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML"><table class="table">
            <thead><tr>
                <th scope="col"><?= $h->sortLink($h->tr('Token'), 'name', $page->sort, $query, 'admin.api.tokens') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('User'), 'username', $page->sort, $query, 'admin.api.tokens') ?></th>
                <th scope="col"><?= $h->t('Scopes') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Created'), 'created_at', $page->sort, $query, 'admin.api.tokens') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Last used'), 'last_used_at', $page->sort, $query, 'admin.api.tokens') ?></th>
                <th scope="col"><?= $h->sortLink($h->tr('Expires'), 'expires_at', $page->sort, $query, 'admin.api.tokens') ?></th>
                <th scope="col"><?= $h->t('Status') ?></th>
                <th scope="col"><span class="sr-only"><?= $h->t('Actions') ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $k): ?>
                <tr>
                    <td><?= $h->e($k['name']) ?></td>
                    <td><a class="mono" href="<?= $h->url('admin.users.view', ['id' => $k['user_id']]) ?>"><?= $h->e($k['username']) ?></a><div class="small muted"><?= $h->e($k['tenant_name'] ?? '') ?></div></td>
                    <td><?php foreach ($k['scopes'] as $s): ?><span class="tag"><?= $h->e($s) ?></span><?php endforeach ?></td>
                    <td class="small nowrap"><?= $h->datetime($k['created_at'], 'Y-m-d') ?></td>
                    <td class="small nowrap"><?= $h->ago($k['last_used_at']) ?><?= $k['last_used_ip'] ? '<div class="mono muted">' . $h->e($k['last_used_ip']) . '</div>' : '' ?></td>
                    <td class="small nowrap"><?= $h->datetime($k['expires_at'], 'Y-m-d') ?></td>
                    <td><?= $h->badge(match ($k['state']) { 'active' => 'active', 'expired' => 'failed', default => 'blocked' }, $h->tr($k['state'])) ?></td>
                    <td class="actions">
                        <?php if ($k['state'] === 'active' && $can('api.manage')): ?>
                            <form method="post" action="<?= $h->url('admin.api.tokens.revoke', ['id' => $k['id']]) ?>" data-confirm="<?= $h->t('Revoke token {n} of {u}?', ['n' => $k['name'], 'u' => $k['username']]) ?>">
                                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                                <button class="btn btn--sm btn--danger" type="submit"><?= $h->t('Revoke') ?></button>
                            </form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.api.tokens', 'query' => $query]) ?>
</div>
