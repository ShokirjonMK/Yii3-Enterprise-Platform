<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $inbox
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var list<string> $statuses
 * @var array $query
 */
$this->setTitle($h->tr('Approvals'));
$this->setParameter('breadcrumbs', [$h->tr('Workflow & Approvals') => $h->url('admin.workflow'), $h->tr('Approvals')]);
$route = 'admin.workflow.approvals';
$row = static function (array $a) use ($h): string {
    return '<tr><td><a href="' . $h->url('admin.workflow.approval', ['id' => $a['id']]) . '">' . $h->e($a['title']) . '</a></td>'
        . '<td class="mono small">' . $h->e($a['type']) . '</td>'
        . '<td class="small">' . $h->e($a['requested_by_name'] ?? '') . '</td>'
        . '<td class="small">' . (int) $a['approvals'] . '/' . (int) $a['required_approvals'] . '</td>'
        . '<td>' . $h->badge($a['status']) . '</td>'
        . '<td class="small nowrap">' . $h->ago($a['created_at']) . ($a['expires_at'] ? ' · ' . $h->t('expires {d}', ['d' => $h->datetime($a['expires_at'])]) : '') . '</td></tr>';
};
$head = '<thead><tr><th>' . $h->t('Request') . '</th><th>' . $h->t('Type') . '</th><th>' . $h->t('Requested by') . '</th><th>' . $h->t('Approvals') . '</th><th>' . $h->t('Status') . '</th><th>' . $h->t('Created') . '</th></tr></thead>';
?>
<?= $this->render('./_tabs', ['active' => 'approvals']) ?>
<div class="card" id="inbox">
    <div class="card__head"><h2><?= $h->icon('flow') ?> <?= $h->t('Waiting for my decision') ?></h2><span class="badge badge--<?= $inbox === [] ? 'muted' : 'warn' ?>"><?= count($inbox) ?></span></div>
    <?php if ($inbox === []): ?>
        <div class="empty"><?= $h->t('Nothing to decide right now.') ?></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--compact"><?= $head ?><tbody><?php foreach ($inbox as $a) {
            echo $row($a);
        } ?></tbody></table></div>
    <?php endif ?>
</div>
<div class="card">
    <div class="card__head"><h2><?= $h->t('All requests') ?></h2></div>
    <form class="toolbar" method="get" action="<?= $h->url($route) ?>" hx-get="<?= $h->url($route) ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true" hx-trigger="change">
        <select name="status" aria-label="<?= $h->t('Status') ?>"><option value=""><?= $h->t('All statuses') ?></option>
            <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= ($query['status'] ?? '') === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?></select>
        <label class="check"><input type="checkbox" name="mine" value="1"<?= ($query['mine'] ?? '') === '1' ? ' checked' : '' ?>> <?= $h->t('Requested by me') ?></label>
        <noscript><button class="btn" type="submit"><?= $h->t('Filter') ?></button></noscript>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->t('No approval requests.') ?></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--compact"><?= $head ?><tbody><?php foreach ($page->items as $a) {
            echo $row($a);
        } ?></tbody></table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => $route, 'query' => $query]) ?>
</div>
