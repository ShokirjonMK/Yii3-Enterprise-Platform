<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var App\Platform\Identity\Domain\Identity|null $identity
 * @var callable(string):bool $can
 * @var array $platform
 */

$this->setTitle($platform['name']);
?>
<section class="hero">
    <?php if ($identity): ?>
        <h1><?= $h->t('Welcome, {name}', ['name' => $identity->user->displayName()]) ?></h1>
        <p class="muted"><?= $h->t('Choose where to continue.') ?></p>
    <?php else: ?>
        <h1><?= $h->e($platform['name']) ?></h1>
        <p class="muted"><?= $h->t('A secure foundation for corporate and government information systems.') ?></p>
        <a class="btn btn--primary" href="<?= $h->url('auth.login') ?>"><?= $h->t('Sign in') ?></a>
    <?php endif ?>
</section>

<?php if ($identity): ?>
<div class="grid grid--3">
    <?php if ($can('projects.view')): ?>
    <a class="card kpi" href="<?= $h->url('web.projects') ?>">
        <div class="kpi__label"><?= $h->icon('folder') ?> <?= $h->t('Projects') ?></div>
        <div class="kpi__meta"><?= $h->t('Browse and manage projects') ?></div>
    </a>
    <?php endif ?>
    <a class="card kpi" href="<?= $h->url('web.notifications') ?>">
        <div class="kpi__label"><?= $h->icon('bell') ?> <?= $h->t('Notifications') ?></div>
        <div class="kpi__meta"><?= $h->t('Messages addressed to you') ?></div>
    </a>
    <a class="card kpi" href="<?= $h->url('web.security') ?>">
        <div class="kpi__label"><?= $h->icon('shield') ?> <?= $h->t('Security & sessions') ?></div>
        <div class="kpi__meta"><?= $h->t('Password, two-factor authentication, devices, API tokens') ?></div>
    </a>
    <?php if ($can('admin.access')): ?>
    <a class="card kpi" href="<?= $h->url('admin.dashboard') ?>">
        <div class="kpi__label"><?= $h->icon('dashboard') ?> <?= $h->t('Platform Control Center') ?></div>
        <div class="kpi__meta"><?= $h->t('Administration of the platform') ?></div>
    </a>
    <?php endif ?>
</div>
<?php endif ?>
