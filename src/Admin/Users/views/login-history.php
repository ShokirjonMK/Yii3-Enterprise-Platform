<?php

declare(strict_types=1);

/**
 * Admin · Users & Organizations → Login history (all users; tenant-scoped without tenants.cross).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Shared\Infrastructure\Database\Page $page
 * @var array<string,string> $filters
 * @var array{success:int, failure:int, ips:int, users:int, last_success:?string} $stats
 */

$this->setTitle($h->tr('Login history'));
$this->setParameter('breadcrumbs', [$h->tr('Users & Organizations') => $h->url('admin.users'), $h->tr('Login history')]);
$query = array_filter($filters, static fn($v) => $v !== '');
$hx = 'hx-target="#main" hx-select="#main" hx-swap="outerHTML" hx-push-url="true"';
$methods = ['password' => 'Password', 'api_password' => 'Password (API)', 'sso' => 'SSO', 'totp' => 'Authenticator app', 'webauthn' => 'Passkey / security key',
    'telegram' => 'Telegram', 'email' => 'E-mail', 'sms' => 'SMS', 'voice' => 'Voice call', 'eimzo' => 'E-IMZO', 'recovery' => 'Recovery code', 'trusted_device' => 'Remembered device'];
?>
<div class="page-head">
    <div class="page-head__titles">
        <h1><?= $h->t('Users & Organizations') ?></h1>
        <p class="page-head__sub"><?= $h->t('Sign-in attempts of all users: who, when, from which IP and device, with which method and second factor, and why a failed attempt was refused.') ?></p>
    </div>
</div>
<?= $this->render('./_tabs', ['active' => 'login_history']) ?>

<div class="grid grid--4 mb">
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('check') ?> <?= $h->t('Successful (24 h)') ?></div><div class="kpi__value"><?= $stats['success'] ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('alert') ?> <?= $h->t('Failed (24 h)') ?></div><div class="kpi__value"><?= $stats['failure'] ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('users') ?> <?= $h->t('Users (24 h)') ?></div><div class="kpi__value"><?= $stats['users'] ?></div></div>
    <div class="card kpi"><div class="kpi__label"><?= $h->icon('globe') ?> <?= $h->t('Different IPs (24 h)') ?></div><div class="kpi__value"><?= $stats['ips'] ?></div></div>
</div>

<div class="card">
    <form class="toolbar" method="get" action="<?= $h->url('admin.users.login_history') ?>" hx-get="<?= $h->url('admin.users.login_history') ?>" <?= $hx ?> hx-trigger="change, submit, keyup changed delay:400ms from:input[type=search]">
        <input type="search" name="user" value="<?= $h->e($filters['user']) ?>" placeholder="<?= $h->t('User (login, name, e-mail or id)') ?>" aria-label="<?= $h->t('User') ?>">
        <input type="search" name="ip" value="<?= $h->e($filters['ip']) ?>" placeholder="<?= $h->t('IP address') ?>" aria-label="<?= $h->t('IP address') ?>" class="input--sm">
        <select name="result" aria-label="<?= $h->t('Result') ?>">
            <option value=""><?= $h->t('All results') ?></option>
            <option value="success"<?= $filters['result'] === 'success' ? ' selected' : '' ?>><?= $h->t('Successful') ?></option>
            <option value="failure"<?= $filters['result'] === 'failure' ? ' selected' : '' ?>><?= $h->t('Failed') ?></option>
        </select>
        <select name="method" aria-label="<?= $h->t('Method') ?>">
            <option value=""><?= $h->t('All methods') ?></option>
            <?php foreach ($methods as $k => $v): ?><option value="<?= $h->e($k) ?>"<?= $filters['method'] === $k ? ' selected' : '' ?>><?= $h->t($v) ?></option><?php endforeach ?>
        </select>
        <input type="date" name="from" value="<?= $h->e($filters['from']) ?>" aria-label="<?= $h->t('From') ?>">
        <input type="date" name="to" value="<?= $h->e($filters['to']) ?>" aria-label="<?= $h->t('To') ?>">
        <button class="btn btn--sm" type="submit"><?= $h->icon('search') ?> <?= $h->t('Filter') ?></button>
    </form>
    <?php if ($page->items === []): ?>
        <div class="empty"><?= $h->icon('clock') ?><p><?= $h->t('No sign-ins match the filter.') ?></p></div>
    <?php else: ?>
        <?= $this->render('//components/login-history-table', ['page' => $page, 'query' => $query, 'route' => 'admin.users.login_history', 'showUser' => true, 'userRoute' => 'admin.users.view']) ?>
        <?= $this->render('//components/pager', ['page' => $page, 'route' => 'admin.users.login_history', 'query' => $query]) ?>
    <?php endif ?>
</div>
