<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var string|null $error
 * @var int|null $left
 */

$this->setTitle($h->tr('Two-factor verification'));
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('Two-factor verification') ?></h1>
        <p class="muted"><?= $h->t('Enter the 6-digit code from your authenticator app, or one of your recovery codes.') ?></p>
        <?php if (!empty($error)): ?>
            <div class="alert alert--error" role="alert"><?= $h->t($error) ?><?php if (isset($left)): ?> <?= $h->t('Attempts left: {n}', ['n' => $left]) ?><?php endif ?></div>
        <?php endif ?>
        <form method="post" action="<?= $h->url('auth.mfa.submit') ?>" class="form">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="field">
                <label for="code"><?= $h->t('Verification code') ?></label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="16" class="otp-input">
            </div>
            <button type="submit" class="btn btn--primary"><?= $h->t('Verify') ?></button>
            <a class="btn btn--ghost" href="<?= $h->url('auth.login') ?>"><?= $h->t('Cancel') ?></a>
        </form>
    </div>
</div>
