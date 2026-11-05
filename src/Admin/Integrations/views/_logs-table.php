<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array $query
 * @var string $route
 * @var array $args
 * @var bool $showIntegration
 */
$sort = $page->sort;
?>
<div id="logs-table">
<?php if ($page->items === []): ?>
    <div class="empty"><?= $h->icon('activity') ?><div><?= $h->t('No calls recorded yet.') ?></div></div>
<?php else: ?>
<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
        <th><?= $h->sortLink($h->tr('Time'), 'created_at', $sort, $query, $route, $args) ?></th>
        <?php if ($showIntegration): ?><th><?= $h->t('Integration') ?></th><?php endif ?>
        <th><?= $h->t('Request') ?></th>
        <th><?= $h->sortLink($h->tr('Status'), 'status_code', $sort, $query, $route, $args) ?></th>
        <th class="num"><?= $h->sortLink($h->tr('Duration'), 'duration_ms', $sort, $query, $route, $args) ?></th>
        <th><?= $h->t('Attempt') ?></th>
        <th><?= $h->t('Error') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($page->items as $l): ?>
        <tr>
            <td class="small nowrap"><a href="<?= $h->url('admin.integrations.log', ['logId' => $l['id']]) ?>"><?= $h->datetime($l['created_at'], 'm-d H:i:s') ?></a></td>
            <?php if ($showIntegration): ?><td class="mono small"><?= $h->e($l['integration_code'] ?? '(provider)') ?></td><?php endif ?>
            <td class="small mono"><span class="tag"><?= $h->e($l['method']) ?></span> <?= $h->e(mb_strimwidth((string) $l['url'], 0, 90, '…')) ?></td>
            <td><?= $l['status_code'] === null ? '—' : $h->badge((int) $l['status_code'] < 400 ? 'ok' : 'failed', (string) $l['status_code']) ?></td>
            <td class="num small"><?= $l['duration_ms'] === null ? '—' : $h->e($l['duration_ms'] . ' ms') ?></td>
            <td class="small"><?= (int) $l['attempt'] ?></td>
            <td class="small"><?php if ($l['error_class']): ?><span class="tag"><?= $h->e($l['error_class']) ?></span><?php endif ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table></div>
<?php endif ?>
<?= $this->render('//components/pager', ['page' => $page, 'route' => $route, 'args' => $args, 'query' => $query, 'target' => '#logs-table']) ?>
</div>
