<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var string $return
 * @var bool $mfa
 * @var string|null $error
 */

$this->setTitle($h->tr('Confirm your identity'));
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('Confirm your identity') ?></h1>
        <p class="muted"><?= $h->t('This is a critical operation. Please confirm with your password and two-factor code.') ?></p>
        <?php if (!empty($error)): ?><div class="alert alert--error" role="alert"><?= $h->t($error) ?></div><?php endif ?>
        <form method="post" action="<?= $h->url('auth.confirm.submit') ?>" class="form">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <input type="hidden" name="return" value="<?= $h->e($return) ?>">
            <div class="field">
                <label for="password"><?= $h->t('Password') ?></label>
                <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
            </div>
            <?php if ($mfa): ?>
                <div class="field">
                    <label for="code"><?= $h->t('Verification code') ?></label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="16" class="otp-input">
                </div>
            <?php endif ?>
            <button type="submit" class="btn btn--primary"><?= $h->t('Confirm') ?></button>
            <a class="btn btn--ghost" href="<?= $h->e($return) ?>"><?= $h->t('Cancel') ?></a>
        </form>
    </div>
</div>
