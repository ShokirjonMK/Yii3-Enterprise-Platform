<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $query
 * @var callable(string):bool $can
 */
$this->setTitle($h->tr('Integrations'));
$this->setParameter('breadcrumbs', [$h->tr('Integrations')]);
$sort = $page->sort;
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Integrations') ?></h1>
        <p class="page-head__sub"><?= $h->t('External systems called through the platform HTTP client: timeouts, retries, circuit breaker, SSRF guard, redacted logs.') ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($can('integrations.manage')): ?><a class="btn btn--primary" href="<?= $h->url('admin.integrations.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New integration') ?></a><?php endif ?>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'integrations']) ?>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.integrations') ?>" hx-get="<?= $h->url('admin.integrations') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="submit, change from:select">
        <input type="search" name="q" value="<?= $h->e($query['q'] ?? '') ?>" placeholder="<?= $h->t('Search code, name, URL…') ?>" aria-label="<?= $h->t('Search') ?>">
        <select name="status" aria-label="<?= $h->t('Status') ?>">
            <option value=""><?= $h->t('All statuses') ?></option>
            <?php foreach (App\Platform\Integrations\IntegrationService::STATUSES as $s): ?><option value="<?= $s ?>"<?= ($query['status'] ?? '') === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?>
        </select>
        <button class="btn" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('plug') ?><div><?= $h->t('No integrations configured yet.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('Code'), 'code', $sort, $query, 'admin.integrations') ?></th>
            <th><?= $h->sortLink($h->tr('Name'), 'name', $sort, $query, 'admin.integrations') ?></th>
            <th><?= $h->t('Base URL') ?></th>
            <th><?= $h->t('Enabled') ?></th>
            <th><?= $h->sortLink($h->tr('Status'), 'status', $sort, $query, 'admin.integrations') ?></th>
            <th><?= $h->t('Circuit') ?></th>
            <th><?= $h->sortLink($h->tr('Updated'), 'updated_at', $sort, $query, 'admin.integrations') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $r): ?>
            <tr>
                <td><a class="mono" href="<?= $h->url('admin.integrations.show', ['id' => $r['id']]) ?>"><?= $h->e($r['code']) ?></a></td>
                <td><?= $h->e($r['name']) ?></td>
                <td class="small mono"><?= $h->e($r['base_url'] ?? '—') ?></td>
                <td><?= App\Shared\Infrastructure\Database\Sql::bool($r['enabled']) ? $h->badge('enabled') : $h->badge('disabled') ?></td>
                <td><?= $h->badge($r['status']) ?></td>
                <td><?= $this->render('./_breaker', ['breaker' => $r['breaker']]) ?></td>
                <td class="small nowrap"><?= $h->ago($r['updated_at']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.integrations', 'query' => $query]) ?>
</div>
