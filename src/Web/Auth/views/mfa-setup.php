<?php

declare(strict_types=1);

/**
 * Two-factor enrolment: one tab per method allowed by the user's role policy.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var App\Platform\Identity\Domain\User $user
 * @var list<string> $allowed
 * @var list<array> $enrolled
 * @var list<string> $enrolledTypes
 * @var string $selected
 * @var bool $forced
 * @var bool $required
 * @var string|null $qr       authenticator app QR (SVG, server-side)
 * @var string|null $secret
 * @var array|null $pending   e-mail / SMS / voice code sent to ['target','hint']
 * @var string|null $defaultTarget
 * @var array|null $telegramLink ['url','bot']
 */

use App\Platform\Identity\Mfa\MfaMethodType;

$this->setTitle($h->tr('Set up two-factor authentication'));
$csrfField = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$isEnrolled = static fn(string $t): bool => in_array($t, $enrolledTypes, true);
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('Set up two-factor authentication') ?></h1>
        <?php if ($forced): ?>
            <div class="alert alert--warning"><?= $h->t('Your role requires two-factor authentication.') ?></div>
        <?php endif ?>

        <?php if ($allowed === []): ?>
            <div class="alert alert--error"><?= $h->t('No two-factor method is enabled for your account. Contact your administrator.') ?></div>
        <?php else: ?>
        <p class="small muted"><?= $h->t('Choose how you want to confirm your sign-in. You can add several methods and pick one each time.') ?></p>
        <nav class="tabs tabs--wrap" aria-label="<?= $h->t('Methods') ?>">
            <?php foreach ($allowed as $t): ?>
                <a<?= $t === $selected ? ' aria-current="page"' : '' ?> href="<?= $h->url('auth.mfa.setup', [], ['method' => $t]) ?>"><?= $h->icon(MfaMethodType::ICONS[$t] ?? 'key', 'icon--sm') ?> <?= $h->t(MfaMethodType::label($t)) ?><?= $isEnrolled($t) && $t !== MfaMethodType::WEBAUTHN ? ' ✓' : '' ?></a>
            <?php endforeach ?>
        </nav>

        <?php if ($selected === MfaMethodType::TOTP): ?>
            <?php if ($isEnrolled('totp')): ?><div class="alert alert--info"><?= $h->t('An authenticator app is already connected. Scanning a new code replaces it.') ?></div><?php endif ?>
            <ol class="small">
                <li><?= $h->t('Install an authenticator app (Google Authenticator, Microsoft Authenticator, FreeOTP, Aegis).') ?></li>
                <li><?= $h->t('Scan the QR code or enter the key manually.') ?></li>
                <li><?= $h->t('Enter the 6-digit code shown by the app.') ?></li>
            </ol>
            <div class="row"><div class="qr" aria-label="<?= $h->t('QR code') ?>"><?= preg_replace('/<\?xml[^>]*\?>/', '', (string) $qr) ?></div></div>
            <p class="small mt"><?= $h->t('Manual key') ?>: <code><?= $h->e(trim(chunk_split((string) $secret, 4, ' '))) ?></code></p>
            <form method="post" action="<?= $h->url('auth.mfa.setup.confirm') ?>" class="form">
                <?= $csrfField ?>
                <div class="field">
                    <label for="code"><?= $h->t('Verification code') ?></label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="6" class="otp-input" autofocus>
                </div>
                <button type="submit" class="btn btn--primary"><?= $h->t('Enable two-factor authentication') ?></button>
            </form>

        <?php elseif ($selected === MfaMethodType::TELEGRAM): ?>
            <?php if ($isEnrolled('telegram')): ?><div class="alert alert--info"><?= $h->t('Telegram is already connected. Connecting again replaces the chat.') ?></div><?php endif ?>
            <p><?= $h->t('Sign-in codes will come from our Telegram bot. Press the button, open the bot and press START — that is all.') ?></p>
            <?php if (empty($telegramLink)): ?>
                <form method="post" action="<?= $h->url('auth.mfa.setup.telegram') ?>"><?= $csrfField ?>
                    <button type="submit" class="btn btn--primary"><?= $h->icon('bell') ?> <?= $h->t('Connect Telegram') ?></button>
                </form>
            <?php else: ?>
                <p><a class="btn btn--primary" href="<?= $h->e($telegramLink['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $h->icon('bell') ?> <?= $h->t('Open @{bot} in Telegram', ['bot' => $telegramLink['bot']]) ?></a></p>
                <p class="small muted"><?= $h->t('On another device? Open Telegram, find @{bot} and send it this link — or scan the link from your phone.', ['bot' => $telegramLink['bot']]) ?></p>
                <div hx-get="<?= $h->url('auth.mfa.setup.telegram.status') ?>" hx-trigger="load delay:2s, every 2s" hx-swap="innerHTML">
                    <?= $this->render('./_telegram-status', ['status' => 'pending']) ?>
                </div>
            <?php endif ?>

        <?php elseif (in_array($selected, [MfaMethodType::EMAIL, MfaMethodType::SMS, MfaMethodType::VOICE], true)): ?>
            <?php if ($isEnrolled($selected)): ?><div class="alert alert--info"><?= $h->t('Already set up. Verifying a new address replaces the current one.') ?></div><?php endif ?>
            <?php $isMail = $selected === MfaMethodType::EMAIL; ?>
            <p><?= match ($selected) {
                'email' => $h->t('Enter an e-mail address you can read. We will send a code to confirm it.'),
                'sms' => $h->t('Enter your mobile number. We will send an SMS with a code to confirm it.'),
                default => $h->t('Enter your phone number. We will call you and read a code aloud to confirm it.'),
            } ?></p>
            <form method="post" action="<?= $h->url('auth.mfa.setup.otp.send') ?>" class="form">
                <?= $csrfField ?>
                <input type="hidden" name="method" value="<?= $h->e($selected) ?>">
                <div class="field">
                    <label for="target"><?= $h->t($isMail ? 'E-mail' : 'Phone number') ?></label>
                    <input id="target" name="target" type="<?= $isMail ? 'email' : 'tel' ?>" required maxlength="254"
                           value="<?= $h->e($pending['target'] ?? $defaultTarget ?? '') ?>" placeholder="<?= $isMail ? 'name@example.com' : '+998901234567' ?>" autocomplete="<?= $isMail ? 'email' : 'tel' ?>">
                </div>
                <button type="submit" class="btn<?= $pending ? '' : ' btn--primary' ?>"><?= $h->t($pending ? 'Send again' : ($selected === 'voice' ? 'Call me' : 'Send code')) ?></button>
            </form>
            <?php if (!empty($pending)): ?>
            <form method="post" action="<?= $h->url('auth.mfa.setup.otp.verify') ?>" class="form mt">
                <?= $csrfField ?>
                <div class="field">
                    <label for="code"><?= $h->t('Code sent to {hint}', ['hint' => $pending['hint']]) ?></label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="8" class="otp-input" autofocus>
                </div>
                <button type="submit" class="btn btn--primary"><?= $h->t('Confirm') ?></button>
            </form>
            <?php endif ?>

        <?php elseif ($selected === MfaMethodType::WEBAUTHN): ?>
            <p><?= $h->t('A passkey uses your fingerprint, face, device PIN or a USB/NFC security key. It cannot be phished and needs no codes.') ?></p>
            <form method="post" action="<?= $h->url('auth.mfa.setup.passkey') ?>" class="form" data-webauthn="create" data-options-url="<?= $h->url('auth.mfa.setup.passkey.options') ?>">
                <?= $csrfField ?>
                <input type="hidden" name="credential" value="">
                <div class="field">
                    <label for="label"><?= $h->t('Name') ?></label>
                    <input id="label" name="label" maxlength="60" placeholder="<?= $h->t('e.g. Work laptop, YubiKey') ?>">
                </div>
                <button type="submit" class="btn btn--primary"><?= $h->icon('shield') ?> <?= $h->t('Register passkey') ?></button>
                <p class="small muted mt" data-webauthn-status role="status"></p>
            </form>

        <?php elseif ($selected === MfaMethodType::EIMZO): ?>
            <?php if ($isEnrolled('eimzo')): ?><div class="alert alert--info"><?= $h->t('An E-IMZO key holder is already linked. Signing with another key replaces it.') ?></div><?php endif ?>
            <p><?= $h->t('Install and start the E-IMZO application, insert your key (or choose the .pfx file) and sign. Your PINFL / TIN is linked, so a renewed certificate keeps working.') ?></p>
            <form method="post" action="<?= $h->url('auth.mfa.setup.eimzo') ?>" class="form" data-eimzo data-challenge-url="<?= $h->url('auth.mfa.setup.eimzo.challenge') ?>">
                <?= $csrfField ?>
                <input type="hidden" name="pkcs7" value="">
                <div class="field">
                    <label for="eimzo-key"><?= $h->t('Key') ?></label>
                    <select id="eimzo-key" data-eimzo-keys disabled><option value=""><?= $h->t('Loading keys from E-IMZO…') ?></option></select>
                </div>
                <button type="submit" class="btn btn--primary" disabled data-eimzo-sign><?= $h->icon('file') ?> <?= $h->t('Sign and link') ?></button>
                <p class="small muted mt" data-eimzo-status role="status"></p>
            </form>
        <?php endif ?>
        <?php endif ?>

        <?php if ($enrolled !== []): ?>
            <hr>
            <p class="small muted"><?= $h->t('Connected') ?>:
                <?php foreach ($enrolled as $m): ?><span class="tag"><?= $h->t(MfaMethodType::label($m['type'])) ?><?= $m['target_hint'] !== '' ? ' · ' . $h->e($m['target_hint']) : '' ?></span> <?php endforeach ?>
            </p>
        <?php endif ?>
        <div class="row mt">
            <?php if (!$forced): ?><a class="btn btn--ghost btn--sm" href="<?= $h->url('web.security') ?>"><?= $h->t('Back to security settings') ?></a><?php endif ?>
            <form method="post" action="<?= $h->url('auth.logout') ?>" class="inline"><?= $csrfField ?><button type="submit" class="btn btn--ghost btn--sm"><?= $h->t('Sign out') ?></button></form>
        </div>
    </div>
</div>
