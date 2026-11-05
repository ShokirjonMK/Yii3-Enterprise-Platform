<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var string $sort
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('Audit & Compliance'));
$this->setParameter('breadcrumbs', [$h->tr('Audit & Compliance')]);
$query = array_filter($filters + ['sort' => $sort], static fn($v) => $v !== '');
$categories = ['security', 'auth', 'access', 'data', 'config', 'ops', 'system', 'integration'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Audit & Compliance') ?></h1>
        <p class="page-head__sub"><?= $h->t('Immutable, HMAC-signed audit trail of every state change and security event.') ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('admin.audit.activity') ?>"><?= $h->icon('activity') ?> <?= $h->t('User activity') ?></a>
        <a class="btn" href="<?= $h->url('admin.audit.partitions') ?>"><?= $h->icon('database') ?> <?= $h->t('Integrity & partitions') ?></a>
        <?php if ($can('audit.export')): ?>
            <a class="btn btn--primary" href="<?= $h->e($h->url('admin.audit.export', [], array_filter($filters, static fn($v) => $v !== ''))) ?>" download><?= $h->icon('download') ?> <?= $h->t('Export CSV') ?></a>
        <?php endif ?>
    </div>
</div>

<div class="card">
    <form class="toolbar ops-filters" method="get" action="<?= $h->url('admin.audit') ?>" hx-get="<?= $h->url('admin.audit') ?>" hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true">
        <label class="ops-filters__field"><span class="small muted"><?= $h->t('From') ?></span><input type="date" name="from" value="<?= $h->e($filters['from']) ?>"></label>
        <label class="ops-filters__field"><span class="small muted"><?= $h->t('To') ?></span><input type="date" name="to" value="<?= $h->e($filters['to']) ?>"></label>
        <input type="search" name="actor" value="<?= $h->e($filters['actor']) ?>" placeholder="<?= $h->t('Actor (username, id, system)') ?>" aria-label="<?= $h->t('Actor') ?>">
        <input type="search" name="action" value="<?= $h->e($filters['action']) ?>" placeholder="<?= $h->t('Action prefix, e.g. auth.') ?>" aria-label="<?= $h->t('Action') ?>">
        <select name="category" aria-label="<?= $h->t('Category') ?>">
            <option value=""><?= $h->t('All categories') ?></option>
            <?php foreach ($categories as $c): ?><option value="<?= $c ?>"<?= $filters['category'] === $c ? ' selected' : '' ?>><?= $h->e($c) ?></option><?php endforeach ?>
        </select>
        <select name="result" aria-label="<?= $h->t('Result') ?>">
            <option value=""><?= $h->t('Any result') ?></option>
            <?php foreach (App\Admin\Audit\AuditSearch::RESULTS as $r): ?><option value="<?= $r ?>"<?= $filters['result'] === $r ? ' selected' : '' ?>><?= $h->t($r) ?></option><?php endforeach ?>
        </select>
        <select name="severity" aria-label="<?= $h->t('Severity') ?>">
            <option value=""><?= $h->t('Any severity') ?></option>
            <?php foreach (App\Admin\Audit\AuditSearch::SEVERITIES as $s): ?><option value="<?= $s ?>"<?= $filters['severity'] === $s ? ' selected' : '' ?>><?= $h->t($s) ?></option><?php endforeach ?>
        </select>
        <input type="search" name="entity_type" value="<?= $h->e($filters['entity_type']) ?>" placeholder="<?= $h->t('Entity type') ?>" aria-label="<?= $h->t('Entity type') ?>">
        <input type="search" name="entity_id" value="<?= $h->e($filters['entity_id']) ?>" placeholder="<?= $h->t('Entity id') ?>" aria-label="<?= $h->t('Entity id') ?>">
        <input type="search" name="correlation_id" value="<?= $h->e($filters['correlation_id']) ?>" placeholder="<?= $h->t('Correlation id') ?>" aria-label="<?= $h->t('Correlation id') ?>">
        <input type="search" name="q" value="<?= $h->e($filters['q']) ?>" placeholder="<?= $h->t('Free text') ?>" aria-label="<?= $h->t('Free text') ?>">
        <button class="btn btn--primary btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Search') ?></button>
        <a class="btn btn--ghost btn--sm" href="<?= $h->url('admin.audit') ?>"><?= $h->t('Reset') ?></a>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('file') ?><p><?= $h->t('No audit records match the filters.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table table--compact">
            <thead><tr>
                <th><?= $h->sortLink($h->tr('Time'), 'occurred_at', $sort, $query, 'admin.audit') ?></th>
                <th><?= $h->sortLink($h->tr('Category'), 'category', $sort, $query, 'admin.audit') ?></th>
                <th><?= $h->sortLink($h->tr('Action'), 'action', $sort, $query, 'admin.audit') ?></th>
                <th><?= $h->t('Actor') ?></th>
                <th><?= $h->t('Entity') ?></th>
                <th><?= $h->t('IP') ?></th>
                <th><?= $h->sortLink($h->tr('Result'), 'result', $sort, $query, 'admin.audit') ?></th>
                <th><?= $h->sortLink($h->tr('Severity'), 'severity', $sort, $query, 'admin.audit') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($page->items as $a): ?>
                <tr>
                    <td class="nowrap small"><a href="<?= $h->url('admin.audit.view', ['id' => $a['id']]) ?>"><?= $h->datetime($a['occurred_at'], 'Y-m-d H:i:s') ?></a></td>
                    <td><span class="tag"><?= $h->e($a['category']) ?></span></td>
                    <td><code><?= $h->e($a['action']) ?></code></td>
                    <td class="small"><?= $h->e($a['actor'] ?? ($a['actor_id'] ? substr((string) $a['actor_id'], 0, 8) : 'system')) ?></td>
                    <td class="small"><?= $h->e(trim(($a['entity_type'] ?? '') . ' ' . mb_strimwidth((string) ($a['entity_id'] ?? ''), 0, 18, '…'))) ?></td>
                    <td class="small mono"><?= $h->e($a['ip']) ?></td>
                    <td><?= $h->badge($a['result']) ?></td>
                    <td><?= $h->badge($a['severity']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.audit', 'query' => $query]) ?>
</div>
