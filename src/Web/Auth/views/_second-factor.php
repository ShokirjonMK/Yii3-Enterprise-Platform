<?php

declare(strict_types=1);

/**
 * Second-factor challenge (sign-in and step-up re-authentication).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var list<array> $methods       usable enrolled methods
 * @var string $selected           method type or "recovery"
 * @var array|null $current
 * @var bool $codeSent
 * @var int $cooldown
 * @var int $recoveryLeft
 * @var string $purpose            login | reauth
 * @var array<string,string> $routes submit, send, self, passkey, eimzo
 * @var array<string,string> $selfQuery extra query for method links
 * @var string $extraFields       hidden fields (e.g. password, return) rendered inside every form
 * @var int $rememberDays
 */

use App\Platform\Identity\Mfa\MfaMethodType;

$csrfField = '<input type="hidden" name="_csrf" value="' . $h->e($csrf) . '">';
$extraFields ??= '';
$selfQuery ??= [];
$rememberDays ??= 0;
$remember = $rememberDays > 0 && $purpose === 'login'
    ? '<label class="check small mt"><input type="checkbox" name="remember" value="1"> ' . $h->t('Trust this device for {n} days', ['n' => $rememberDays]) . '</label>'
    : '';
$hint = $current['target_hint'] ?? '';
?>
<?php if (count($methods) > 1 || $recoveryLeft > 0): ?>
<nav class="tabs tabs--wrap mb" aria-label="<?= $h->t('Verification method') ?>">
    <?php foreach ($methods as $m): ?>
        <a<?= $m['type'] === $selected ? ' aria-current="page"' : '' ?> href="<?= $h->url($routes['self'], [], $selfQuery + ['method' => $m['type']]) ?>"><?= $h->icon(MfaMethodType::ICONS[$m['type']] ?? 'key', 'icon--sm') ?> <?= $h->t(MfaMethodType::label($m['type'])) ?></a>
    <?php endforeach ?>
    <?php if ($recoveryLeft > 0): ?>
        <a<?= $selected === 'recovery' ? ' aria-current="page"' : '' ?> href="<?= $h->url($routes['self'], [], $selfQuery + ['method' => 'recovery']) ?>"><?= $h->icon('archive', 'icon--sm') ?> <?= $h->t('Recovery code') ?></a>
    <?php endif ?>
</nav>
<?php endif ?>

<?php if ($selected === MfaMethodType::TOTP || $selected === 'recovery'): ?>
    <p class="muted"><?= $selected === 'recovery'
        ? $h->t('Enter one of your single-use recovery codes ({n} left).', ['n' => $recoveryLeft])
        : $h->t('Enter the 6-digit code from your authenticator app.') ?></p>
    <form method="post" action="<?= $h->url($routes['submit']) ?>" class="form">
        <?= $csrfField ?><?= $extraFields ?>
        <input type="hidden" name="method" value="<?= $h->e($selected) ?>">
        <div class="field">
            <label for="code"><?= $h->t($selected === 'recovery' ? 'Recovery code' : 'Verification code') ?></label>
            <input id="code" name="code" type="text" <?= $selected === 'recovery' ? 'autocomplete="off" maxlength="16"' : 'inputmode="numeric" autocomplete="one-time-code" maxlength="8"' ?> required autofocus class="otp-input">
        </div>
        <?= $remember ?>
        <button type="submit" class="btn btn--primary"><?= $h->t('Verify') ?></button>
    </form>

<?php elseif (MfaMethodType::isOtp($selected)): ?>
    <p class="muted">
        <?= match ($selected) {
            'telegram' => $h->t('We will send a code to your Telegram ({hint}).', ['hint' => $hint]),
            'email' => $h->t('We will send a code to {hint}.', ['hint' => $hint]),
            'sms' => $h->t('We will send an SMS with a code to {hint}.', ['hint' => $hint]),
            'voice' => $h->t('We will call {hint} and read the code aloud.', ['hint' => $hint]),
            default => '',
        } ?>
    </p>
    <form method="post" action="<?= $h->url($routes['send']) ?>" class="mb">
        <?= $csrfField ?><?= $extraFields ?>
        <input type="hidden" name="method" value="<?= $h->e($selected) ?>">
        <button type="submit" class="btn<?= $codeSent ? '' : ' btn--primary' ?>"<?= $cooldown > 0 ? ' disabled' : '' ?>>
            <?= $h->icon($selected === 'voice' ? 'bolt' : 'play') ?>
            <?= $codeSent ? $h->t($selected === 'voice' ? 'Call again' : 'Send again') : $h->t($selected === 'voice' ? 'Call me' : 'Send code') ?>
        </button>
        <?php if ($cooldown > 0): ?><span class="small muted"><?= $h->t('available in {n} s', ['n' => $cooldown]) ?></span><?php endif ?>
    </form>
    <?php if ($codeSent): ?>
    <form method="post" action="<?= $h->url($routes['submit']) ?>" class="form">
        <?= $csrfField ?><?= $extraFields ?>
        <input type="hidden" name="method" value="<?= $h->e($selected) ?>">
        <div class="field">
            <label for="code"><?= $h->t('Verification code') ?></label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="8" class="otp-input">
        </div>
        <?= $remember ?>
        <button type="submit" class="btn btn--primary"><?= $h->t('Verify') ?></button>
    </form>
    <?php endif ?>

<?php elseif ($selected === MfaMethodType::WEBAUTHN): ?>
    <p class="muted"><?= $h->t('Use your passkey or security key: fingerprint, face, device PIN or a USB/NFC key.') ?></p>
    <form method="post" action="<?= $h->url($routes['submit']) ?>" class="form" data-webauthn="get" data-options-url="<?= $h->url($routes['passkey']) ?>">
        <?= $csrfField ?><?= $extraFields ?>
        <input type="hidden" name="method" value="webauthn">
        <input type="hidden" name="credential" value="">
        <?= $remember ?>
        <button type="submit" class="btn btn--primary"><?= $h->icon('shield') ?> <?= $h->t('Use passkey') ?></button>
        <p class="small muted mt" data-webauthn-status role="status"></p>
    </form>

<?php elseif ($selected === MfaMethodType::EIMZO): ?>
    <p class="muted"><?= $h->t('Sign the request with your E-IMZO key. The E-IMZO application must be running on this computer.') ?></p>
    <form method="post" action="<?= $h->url($routes['submit']) ?>" class="form" data-eimzo data-challenge-url="<?= $h->url($routes['eimzo']) ?>">
        <?= $csrfField ?><?= $extraFields ?>
        <input type="hidden" name="method" value="eimzo">
        <input type="hidden" name="pkcs7" value="">
        <div class="field">
            <label for="eimzo-key"><?= $h->t('Key') ?></label>
            <select id="eimzo-key" data-eimzo-keys disabled><option value=""><?= $h->t('Loading keys from E-IMZO…') ?></option></select>
        </div>
        <?= $remember ?>
        <button type="submit" class="btn btn--primary" disabled data-eimzo-sign><?= $h->icon('file') ?> <?= $h->t('Sign and continue') ?></button>
        <p class="small muted mt" data-eimzo-status role="status"></p>
    </form>
<?php else: ?>
    <div class="alert alert--warning"><?= $h->t('No verification method is available. Use a recovery code or contact your administrator.') ?></div>
<?php endif ?>
