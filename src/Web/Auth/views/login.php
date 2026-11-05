<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var string|null $error
 * @var string|null $login
 * @var string|null $reason
 * @var bool $localEnabled
 * @var list<array{code:string,name:string,type:string}> $providers
 */

$this->setTitle($h->tr('Sign in'));
$reasons = [
    'idle_timeout' => 'Your session expired due to inactivity.',
    'absolute_timeout' => 'Your session has expired. Please sign in again.',
    'revoked' => 'Your session was terminated.',
];
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('Sign in') ?></h1>
        <p class="muted"><?= $h->t('Use your corporate account or credentials.') ?></p>

        <?php if (!empty($reason) && isset($reasons[$reason])): ?>
            <div class="alert alert--info" role="status"><?= $h->t($reasons[$reason]) ?></div>
        <?php endif ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert--error" role="alert"><?= $h->t($error) ?></div>
        <?php endif ?>

        <?php if ($providers !== []): ?>
            <div class="stack">
                <?php foreach ($providers as $p): ?>
                    <a class="btn sso-btn" href="<?= $h->url('auth.sso.start', ['provider' => $p['code']]) ?>">
                        <?= $h->icon($p['type'] === 'oneid' ? 'shield' : 'key') ?> <?= $h->t('Continue with {name}', ['name' => $p['name']]) ?>
                    </a>
                <?php endforeach ?>
            </div>
            <?php if ($localEnabled): ?><div class="auth__divider"><?= $h->t('or') ?></div><?php endif ?>
        <?php endif ?>

        <form method="post" action="<?= $h->url('auth.login.submit') ?>" class="form" novalidate>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="field">
                <label for="login"><?= $h->t('Username or e-mail') ?></label>
                <input id="login" name="login" type="text" autocomplete="username" required autofocus value="<?= $h->e($login ?? '') ?>" maxlength="254">
            </div>
            <div class="field">
                <label for="password"><?= $h->t('Password') ?></label>
                <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="256">
            </div>
            <button type="submit" class="btn btn--primary"><?= $h->t('Sign in') ?></button>
            <?php if (!$localEnabled): ?>
                <p class="small muted"><?= $h->t('Password sign-in is restricted to break-glass accounts.') ?></p>
            <?php endif ?>
        </form>
    </div>
</div>
