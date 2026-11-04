<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var App\Platform\Identity\Domain\User $user
 * @var list<string> $roles
 * @var array<string, list<string>>|null $errors
 * @var array $platform
 */

$this->setTitle($h->tr('My profile'));
$errors ??= [];
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('My profile') ?></h1><p class="page-head__sub"><?= $h->e($user->username) ?></p></div>
    <div class="page-head__actions"><a class="btn" href="<?= $h->url('web.security') ?>"><?= $h->icon('shield') ?> <?= $h->t('Security & sessions') ?></a></div>
</div>
<div class="grid grid--sidebar">
    <form class="card" method="post" action="<?= $h->url('web.profile.update') ?>" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
        <div class="card__head"><h2><?= $h->t('Personal data') ?></h2></div>
        <div class="card__body form form--2">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="field span-2<?= isset($errors['full_name']) ? ' field--invalid' : '' ?>">
                <label for="full_name"><?= $h->t('Full name') ?></label>
                <input id="full_name" name="full_name" type="text" value="<?= $h->e($user->fullName) ?>" maxlength="255">
                <?php foreach ($errors['full_name'] ?? [] as $e): ?><div class="field__error"><?= $h->t($e) ?></div><?php endforeach ?>
            </div>
            <div class="field">
                <label for="email"><?= $h->t('E-mail') ?></label>
                <input id="email" type="email" value="<?= $h->e($user->email) ?>" disabled>
                <div class="field__hint"><?= $h->t('Managed by the administrator') ?></div>
            </div>
            <div class="field<?= isset($errors['phone']) ? ' field--invalid' : '' ?>">
                <label for="phone"><?= $h->t('Phone') ?></label>
                <input id="phone" name="phone" type="tel" value="<?= $h->e($user->phone) ?>" maxlength="20">
                <?php foreach ($errors['phone'] ?? [] as $e): ?><div class="field__error"><?= $h->t($e) ?></div><?php endforeach ?>
            </div>
            <div class="field">
                <label for="locale"><?= $h->t('Language') ?></label>
                <select id="locale" name="locale">
                    <?php foreach ($platform['locales'] as $code => $label): ?>
                        <option value="<?= $h->e($code) ?>"<?= $user->locale === $code ? ' selected' : '' ?>><?= $h->e($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>
        <div class="card__foot"><button class="btn btn--primary" type="submit"><?= $h->t('Save') ?></button></div>
    </form>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Account') ?></h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt><?= $h->t('Status') ?></dt><dd><?= $h->badge($user->status) ?></dd>
                <dt><?= $h->t('Sign-in policy') ?></dt><dd><?= $h->t($user->authPolicy) ?></dd>
                <dt><?= $h->t('Roles') ?></dt><dd><?php foreach ($roles as $r): ?><span class="tag"><?= $h->e($r) ?></span><?php endforeach ?></dd>
                <dt><?= $h->t('Two-factor') ?></dt><dd><?= $h->badge($user->mfaEnabled ? 'enabled' : 'disabled') ?></dd>
                <dt><?= $h->t('Last sign-in') ?></dt><dd><?= $h->datetime($user->lastLoginAt) ?></dd>
                <dt><?= $h->t('Created') ?></dt><dd><?= $h->datetime($user->createdAt) ?></dd>
            </dl>
        </div>
    </div>
</div>
