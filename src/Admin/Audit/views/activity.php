<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var array<string,mixed> $stats
 * @var bool $enabled
 * @var callable(string):bool $can
 */

$this->setTitle($h->tr('User activity'));
$this->setParameter('breadcrumbs', [$h->tr('Audit & Compliance') => $h->url('admin.audit'), $h->tr('User activity')]);
$query = array_filter($filters, static fn($v) => $v !== '');
$hx = 'hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"';
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('User activity') ?></h1>
        <p class="page-head__sub"><?= $h->t('Every page and API call of signed-in users: who, what, when, from where, result and duration.') ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('admin.audit') ?>"><?= $h->icon('file') ?> <?= $h->t('Audit log') ?></a>
        <?php if ($can('settings.manage')): ?><a class="btn" href="<?= $h->e($h->urlOrNull('admin.settings.flags') ?? $h->url('admin.settings')) ?>"><?= $h->icon('flag') ?> <?= $h->t('Feature flags') ?></a><?php endif ?>
    </div>
</div>

<?php if (!$enabled): ?>
    <div class="alert alert--warning"><?= $h->t('User activity logging is switched off (feature flag audit.user_activity). Existing records are shown.') ?></div>
<?php endif ?>

<div class="grid grid--4 mb">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('activity') ?> <?= $h->t('Requests (24 h)') ?></div><div class="kpi__value"><?= number_format((int) $stats['requests'], 0, '.', ' ') ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('users') ?> <?= $h->t('Active users (24 h)') ?></div><div class="kpi__value"><?= (int) $stats['users'] ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('clock') ?> <?= $h->t('p95 duration (24 h)') ?></div><div class="kpi__value"><?= (int) $stats['p95'] ?> ms</div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('alert') ?> <?= $h->t('Errors 4xx/5xx (24 h)') ?></div><div class="kpi__value"><?= (int) $stats['errors'] ?></div></div>
</div>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.audit.activity') ?>" hx-get="<?= $h->url('admin.audit.activity') ?>" <?= $hx ?> hx-trigger="change, submit, keyup changed delay:400ms from:input[type=search]">
        <input type="search" name="user" value="<?= $h->e($filters['user']) ?>" placeholder="<?= $h->t('User (username or id)') ?>" aria-label="<?= $h->t('User') ?>">
        <input type="search" name="path" value="<?= $h->e($filters['path']) ?>" placeholder="<?= $h->t('Page / route') ?>" aria-label="<?= $h->t('Page / route') ?>">
        <select name="channel" aria-label="<?= $h->t('Channel') ?>">
            <option value=""><?= $h->t('Web + API') ?></option>
            <?php foreach (['web' => 'Web', 'api' => 'API'] as $k => $v): ?><option value="<?= $k ?>"<?= $filters['channel'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach ?>
        </select>
        <select name="method" aria-label="<?= $h->t('Method') ?>">
            <option value=""><?= $h->t('All methods') ?></option>
            <?php foreach (['GET', 'POST', 'PATCH', 'PUT', 'DELETE'] as $m): ?><option<?= $filters['method'] === $m ? ' selected' : '' ?>><?= $m ?></option><?php endforeach ?>
        </select>
        <select name="status" aria-label="<?= $h->t('Status') ?>">
            <option value=""><?= $h->t('All statuses') ?></option>
            <?php foreach (['2' => '2xx', '3' => '3xx', '4' => '4xx', '5' => '5xx'] as $k => $v): ?><option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach ?>
        </select>
        <input type="date" name="from" value="<?= $h->e($filters['from']) ?>" aria-label="<?= $h->t('From') ?>">
        <input type="date" name="to" value="<?= $h->e($filters['to']) ?>" aria-label="<?= $h->t('To') ?>">
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('activity') ?><p><?= $h->t('No activity recorded yet.') ?></p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
            <th><?= $h->sortLink($h->tr('Time'), 'occurred_at', $page->sort, $query, 'admin.audit.activity') ?></th>
            <th><?= $h->sortLink($h->tr('User'), 'username', $page->sort, $query, 'admin.audit.activity') ?></th>
            <th><?= $h->t('Request') ?></th>
            <th><?= $h->sortLink($h->tr('Status'), 'status', $page->sort, $query, 'admin.audit.activity') ?></th>
            <th class="num"><?= $h->sortLink($h->tr('Duration'), 'duration_ms', $page->sort, $query, 'admin.audit.activity') ?></th>
            <th><?= $h->t('IP') ?></th>
            <th><?= $h->t('Correlation id') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($page->items as $a): $st = (int) $a['status']; ?>
            <tr>
                <td class="nowrap small"><?= $h->datetime($a['occurred_at'], 'Y-m-d H:i:s') ?></td>
                <td><a href="<?= $h->e($h->url('admin.audit.activity', [], ['user' => $a['user_id']])) ?>"><?= $h->e($a['username'] ?? substr((string) $a['user_id'], 0, 8)) ?></a></td>
                <td><span class="tag"><?= $h->e($a['method']) ?></span> <span class="mono small"><?= $h->e($a['path']) ?></span> <span class="small muted"><?= $h->e($a['channel']) ?></span></td>
                <td><?= $h->badge($st < 400 ? 'ok' : ($st < 500 ? 'warning' : 'error'), (string) $st) ?></td>
                <td class="num small"><?= (int) $a['duration_ms'] ?> ms</td>
                <td class="mono small"><?= $h->e($a['ip']) ?></td>
                <td class="mono small"><?= $h->e(substr((string) $a['correlation_id'], 0, 12)) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.audit.activity', 'query' => $query]) ?>
    <?php endif ?>
</div>
