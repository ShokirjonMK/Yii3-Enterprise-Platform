<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var array{pending:int,failed:int,published_24h:int,oldest_pending:?string} $counts
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array{status:string,q:string} $filters
 * @var string $sort
 */

$this->setTitle($h->tr('Outbox'));
$this->setParameter('breadcrumbs', [$h->tr('Kafka Management') => $h->url('admin.kafka'), $h->tr('Outbox')]);
$manage = $can('kafka.manage');
$query = array_filter($filters + ['sort' => $sort], static fn($v) => $v !== '');
$url = $h->url('admin.kafka.outbox');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Transactional outbox') ?></h1>
        <p class="page-head__sub"><?= $h->t('Events committed with business transactions, published by the relay. Rows fail after 20 attempts and can be re-queued.') ?></p>
    </div>
    <?php if ($manage && $counts['failed'] > 0): ?>
    <div class="page-head__actions">
        <form method="post" action="<?= $h->url('admin.kafka.outbox.requeue_all') ?>" data-confirm="<?= $h->t('Re-queue all {n} failed events? Requires re-authentication.', ['n' => $counts['failed']]) ?>">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <button class="btn btn--primary" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Re-queue all failed') ?></button>
        </form>
    </div>
    <?php endif ?>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.kafka.outbox']) ?>

<div id="outbox-live" hx-get="<?= $h->e($h->url('admin.kafka.outbox', [], $query)) ?>" hx-trigger="every 15s" hx-select="#outbox-live" hx-target="this" hx-swap="outerHTML">
<div class="grid grid--4">
    <a class="card kpi" href="<?= $h->url('admin.kafka.outbox', [], ['status' => 'pending']) ?>"><div class="kpi__label"><?= $h->t('Pending') ?></div><div class="kpi__value"><?= (int) $counts['pending'] ?></div><div class="kpi__meta"><?= $h->t('oldest {t}', ['t' => $h->ago($counts['oldest_pending'])]) ?></div></a>
    <a class="card kpi" href="<?= $h->url('admin.kafka.outbox', [], ['status' => 'failed']) ?>"><div class="kpi__label"><?= $h->t('Failed') ?></div><div class="kpi__value"><?= (int) $counts['failed'] ?></div><div class="kpi__meta"><?= $h->t('needs operator action') ?></div></a>
    <a class="card kpi" href="<?= $h->url('admin.kafka.outbox', [], ['status' => 'published']) ?>"><div class="kpi__label"><?= $h->t('Published (24 h)') ?></div><div class="kpi__value"><?= (int) $counts['published_24h'] ?></div><div class="kpi__meta">&nbsp;</div></a>
</div>

<div class="card mt">
    <form class="toolbar" method="get" action="<?= $h->e($url) ?>" hx-get="<?= $h->e($url) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change, keyup changed delay:400ms from:find input[type=search], submit">
        <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Topic, event type, aggregate…') ?>" aria-label="<?= $h->t('Search') ?>">
        <select name="status" aria-label="<?= $h->t('Status') ?>">
            <option value=""><?= $h->t('All statuses') ?></option>
            <?php foreach (['pending', 'failed', 'published'] as $s): ?><option value="<?= $s ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?>
        </select>
        <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->t('No outbox events match.') ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('Created'), 'created_at', $page->sort, $query, 'admin.kafka.outbox') ?></th>
            <th><?= $h->sortLink($h->tr('Topic'), 'topic', $page->sort, $query, 'admin.kafka.outbox') ?></th>
            <th><?= $h->sortLink($h->tr('Event'), 'event_type', $page->sort, $query, 'admin.kafka.outbox') ?></th>
            <th><?= $h->t('Aggregate') ?></th><th><?= $h->t('Status') ?></th>
            <th class="num"><?= $h->sortLink($h->tr('Attempts'), 'attempts', $page->sort, $query, 'admin.kafka.outbox') ?></th>
            <th><?= $h->t('Last error') ?></th><?php if ($manage): ?><th></th><?php endif ?>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $o): ?>
            <tr>
                <td class="nowrap small"><?= $h->datetime($o['created_at'], 'm-d H:i:s') ?></td>
                <td class="mono small"><?= $h->e($o['topic']) ?></td>
                <td><code><?= $h->e($o['event_type']) ?></code></td>
                <td class="small"><?= $h->e($o['aggregate_type']) ?> <span class="mono muted"><?= $h->e(mb_strimwidth((string) $o['aggregate_id'], 0, 14, '…')) ?></span></td>
                <td><?= $h->badge((string) $o['status']) ?><?php if ($o['status'] === 'pending' && $o['attempts'] > 0): ?><div class="small muted"><?= $h->t('retry {t}', ['t' => $h->datetime($o['available_at'], 'H:i:s')]) ?></div><?php endif ?></td>
                <td class="num"><?= (int) $o['attempts'] ?></td>
                <td class="small runtime-error"><?= $h->e(mb_strimwidth((string) ($o['last_error'] ?? ''), 0, 140, '…')) ?></td>
                <?php if ($manage): ?><td class="actions"><?php if ($o['status'] === 'failed'): ?>
                    <form method="post" action="<?= $h->url('admin.kafka.outbox.requeue', ['id' => $o['id']]) ?>"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button class="btn btn--sm" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Re-queue') ?></button></form>
                <?php endif ?></td><?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.kafka.outbox', 'query' => $query]) ?>
</div>
</div>
