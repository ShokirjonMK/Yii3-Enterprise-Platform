<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array{state:string,group:string,q:string} $filters
 * @var string $sort
 * @var list<string> $groups
 */

$this->setTitle($h->tr('Dead letters'));
$this->setParameter('breadcrumbs', [$h->tr('Kafka Management') => $h->url('admin.kafka'), $h->tr('Dead letters')]);
$query = array_filter($filters + ['sort' => $sort], static fn($v) => $v !== '');
$url = $h->url('admin.kafka.dlq');
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Dead letters') ?></h1>
        <p class="page-head__sub"><?= $h->t('Messages that failed 3 attempts (retry topics 1 m → 10 m) or could not be parsed. Mirror of topic platform.dlq.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.kafka.dlq']) ?>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->e($url) ?>" hx-get="<?= $h->e($url) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change, keyup changed delay:400ms from:find input[type=search], submit">
        <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Topic, key, message id, error…') ?>" aria-label="<?= $h->t('Search') ?>">
        <select name="state" aria-label="<?= $h->t('State') ?>">
            <?php foreach (['open' => 'Not replayed', 'replayed' => 'Replayed', 'all' => 'All'] as $k => $label): ?><option value="<?= $k ?>"<?= $filters['state'] === $k ? ' selected' : '' ?>><?= $h->t($label) ?></option><?php endforeach ?>
        </select>
        <select name="group" aria-label="<?= $h->t('Consumer group') ?>">
            <option value=""><?= $h->t('All groups') ?></option>
            <?php foreach ($groups as $g): ?><option value="<?= $h->e($g) ?>"<?= $filters['group'] === $g ? ' selected' : '' ?>><?= $h->e($g) ?></option><?php endforeach ?>
        </select>
        <button class="btn btn--sm" type="submit"><?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('check') ?><div><?= $h->t('No dead letters.') ?></div></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('Failed'), 'failed_at', $page->sort, $query, 'admin.kafka.dlq') ?></th>
            <th><?= $h->sortLink($h->tr('Group'), 'consumer_group', $page->sort, $query, 'admin.kafka.dlq') ?></th>
            <th><?= $h->sortLink($h->tr('Topic'), 'topic', $page->sort, $query, 'admin.kafka.dlq') ?></th>
            <th><?= $h->t('Partition/offset') ?></th>
            <th class="num"><?= $h->sortLink($h->tr('Attempts'), 'attempts', $page->sort, $query, 'admin.kafka.dlq') ?></th>
            <th><?= $h->t('Error') ?></th><th><?= $h->t('Replayed') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $d): ?>
            <tr>
                <td class="nowrap small"><a href="<?= $h->url('admin.kafka.dlq.view', ['id' => $d['id']]) ?>">#<?= (int) $d['id'] ?></a> · <?= $h->ago($d['failed_at']) ?></td>
                <td class="mono small"><?= $h->e($d['consumer_group']) ?></td>
                <td class="mono small"><?= $h->e($d['topic']) ?></td>
                <td class="num small"><?= (int) $d['partition'] ?> / <?= (int) $d['offset'] ?></td>
                <td class="num"><?= (int) $d['attempts'] ?></td>
                <td class="small runtime-error"><?= $h->e(mb_strimwidth((string) $d['error'], 0, 140, '…')) ?></td>
                <td class="small"><?= $d['replayed_at'] ? $h->badge('done', $h->ago($d['replayed_at'])) : '—' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.kafka.dlq', 'query' => $query]) ?>
</div>
