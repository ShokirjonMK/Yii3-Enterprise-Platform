<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var string $qr SVG generated server-side by bacon/bacon-qr-code
 * @var string $secret
 * @var bool $forced
 */

$this->setTitle($h->tr('Set up two-factor authentication'));
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('Set up two-factor authentication') ?></h1>
        <?php if ($forced): ?>
            <div class="alert alert--warning"><?= $h->t('Your role requires two-factor authentication.') ?></div>
        <?php endif ?>
        <ol class="small">
            <li><?= $h->t('Install an authenticator app (Google Authenticator, Microsoft Authenticator, FreeOTP, Aegis).') ?></li>
            <li><?= $h->t('Scan the QR code or enter the key manually.') ?></li>
            <li><?= $h->t('Enter the 6-digit code shown by the app.') ?></li>
        </ol>
        <div class="row">
            <div class="qr" aria-label="<?= $h->t('QR code') ?>"><?= preg_replace('/<\?xml[^>]*\?>/', '', $qr) ?></div>
        </div>
        <p class="small mt"><?= $h->t('Manual key') ?>: <code><?= $h->e(trim(chunk_split($secret, 4, ' '))) ?></code></p>
        <form method="post" action="<?= $h->url('auth.mfa.setup.confirm') ?>" class="form">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="field">
                <label for="code"><?= $h->t('Verification code') ?></label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="6" class="otp-input" autofocus>
            </div>
            <button type="submit" class="btn btn--primary"><?= $h->t('Enable two-factor authentication') ?></button>
        </form>
    </div>
</div>
