<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Database\Sql;

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $query
 * @var callable(string):bool $can
 */
$this->setTitle($h->tr('Inbound webhooks'));
$this->setParameter('breadcrumbs', [$h->tr('Integrations') => $h->url('admin.integrations'), $h->tr('Inbound webhooks')]);
$sort = $page->sort;
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('Integrations') ?></h1>
        <p class="page-head__sub"><span class="mono">POST /webhooks/&lt;code&gt;</span> — <?= $h->t('HMAC-SHA256 over "timestamp.body", replay window, idempotent by event id, processed asynchronously (202).') ?></p></div>
    <div class="page-head__actions">
        <?php if ($can('integrations.manage')): ?><a class="btn btn--primary" href="<?= $h->url('admin.integrations.webhooks.create') ?>"><?= $h->icon('plus') ?> <?= $h->t('New endpoint') ?></a><?php endif ?>
    </div>
</div>
<?= $this->render('//admin/integrations/_tabs', ['active' => 'webhooks']) ?>
<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.integrations.webhooks') ?>" hx-get="<?= $h->url('admin.integrations.webhooks') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
        <input type="search" name="q" value="<?= $h->e($query['q'] ?? '') ?>" placeholder="<?= $h->t('Search code or name…') ?>" aria-label="<?= $h->t('Search') ?>">
        <button class="btn" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('plug') ?><div><?= $h->t('No webhook endpoints yet.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('Code'), 'code', $sort, $query, 'admin.integrations.webhooks') ?></th>
            <th><?= $h->sortLink($h->tr('Name'), 'name', $sort, $query, 'admin.integrations.webhooks') ?></th>
            <th><?= $h->t('URL') ?></th>
            <th><?= $h->t('Enabled') ?></th>
            <th><?= $h->t('Job type') ?></th>
            <th class="num"><?= $h->t('Events') ?></th>
            <th><?= $h->sortLink($h->tr('Last received'), 'last_received_at', $sort, $query, 'admin.integrations.webhooks') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $r): ?>
            <tr>
                <td><a class="mono" href="<?= $h->url('admin.integrations.webhooks.show', ['id' => $r['id']]) ?>"><?= $h->e($r['code']) ?></a></td>
                <td><?= $h->e($r['name']) ?></td>
                <td class="mono small">/webhooks/<?= $h->e($r['code']) ?></td>
                <td><?= Sql::bool($r['enabled']) ? $h->badge('enabled') : $h->badge('disabled') ?></td>
                <td class="mono small"><?= $h->e($r['job_type'] ?? 'platform.webhooks.ack') ?></td>
                <td class="num"><?= (int) $r['events'] ?></td>
                <td class="small"><?= $h->ago($r['last_received_at']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.integrations.webhooks', 'query' => $query]) ?>
</div>
