<?php

declare(strict_types=1);

/**
 * Account → Login history: the user's own sign-ins.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var array{success:int, failure:int, ips:int, users:int, last_success:?string} $stats
 * @var bool $canViewAll
 */

$this->setTitle($h->tr('Login history'));
$query = array_filter($filters, static fn($v) => $v !== '');
$hx = 'hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"';
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Login history') ?></h1>
        <p class="page-head__sub"><?= $h->t('Every sign-in to your account: successful ones and failed attempts (also by somebody else). If you do not recognise an entry, change your password and end your other sessions.') ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= $h->url('web.security') ?>"><?= $h->icon('lock') ?> <?= $h->t('Security & sessions') ?></a>
        <?php if ($canViewAll): ?><a class="btn" href="<?= $h->url('admin.users.login_history') ?>"><?= $h->icon('users') ?> <?= $h->t('All users') ?></a><?php endif ?>
    </div>
</div>

<div class="grid grid--4 mb">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('check') ?> <?= $h->t('Successful (30 days)') ?></div><div class="kpi__value"><?= $stats['success'] ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('alert') ?> <?= $h->t('Failed (30 days)') ?></div><div class="kpi__value"><?= $stats['failure'] ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('globe') ?> <?= $h->t('Different IPs (30 days)') ?></div><div class="kpi__value"><?= $stats['ips'] ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('clock') ?> <?= $h->t('Last sign-in') ?></div><div class="kpi__value small"><?= $stats['last_success'] !== null ? $h->datetime($stats['last_success'], 'Y-m-d H:i') : '—' ?></div></div>
</div>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('web.login_history') ?>" hx-get="<?= $h->url('web.login_history') ?>" <?= $hx ?> hx-trigger="change, submit">
        <select name="result" aria-label="<?= $h->t('Result') ?>">
            <option value=""><?= $h->t('All results') ?></option>
            <option value="success"<?= $filters['result'] === 'success' ? ' selected' : '' ?>><?= $h->t('Successful') ?></option>
            <option value="failure"<?= $filters['result'] === 'failure' ? ' selected' : '' ?>><?= $h->t('Failed') ?></option>
        </select>
        <input type="date" name="from" value="<?= $h->e($filters['from']) ?>" aria-label="<?= $h->t('From') ?>">
        <input type="date" name="to" value="<?= $h->e($filters['to']) ?>" aria-label="<?= $h->t('To') ?>">
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('clock') ?><p><?= $h->t('No sign-ins recorded yet.') ?></p></div>
    <?php else: ?>
        <?= $this->render('//components/login-history-table', ['page' => $page, 'query' => $query, 'route' => 'web.login_history', 'showUser' => false]) ?>
        <?= $this->render('//components/pager', ['page' => $page, 'route' => 'web.login_history', 'query' => $query]) ?>
    <?php endif ?>
</div>
