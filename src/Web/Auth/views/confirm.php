<?php

declare(strict_types=1);

/**
 * Step-up re-authentication: password first (if the account has one), then any usable second factor.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var string $return
 * @var bool $needPassword
 * @var bool $mfa
 * @var string|null $error
 */

$this->setTitle($h->tr('Confirm your identity'));
$params = get_defined_vars();
unset($params['this']);
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('Confirm your identity') ?></h1>
        <p class="muted"><?= $h->t($mfa ? 'This is a critical operation. Please confirm with your password and two-factor code.' : 'This is a critical operation. Please confirm with your password.') ?></p>
        <?php if (!empty($error)): ?><div class="alert alert--error" role="alert"><?= $h->t($error) ?></div><?php endif ?>
        <?php if ($needPassword): ?>
            <form method="post" action="<?= $h->url('auth.confirm.submit') ?>" class="form">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <input type="hidden" name="return" value="<?= $h->e($return) ?>">
                <div class="field">
                    <label for="password"><?= $h->t('Password') ?></label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
                </div>
                <button type="submit" class="btn btn--primary"><?= $h->t($mfa ? 'Continue' : 'Confirm') ?></button>
            </form>
        <?php elseif ($mfa): ?>
            <?= $this->render('./_second-factor', $params) ?>
        <?php endif ?>
        <p class="mt"><a class="btn btn--ghost btn--sm" href="<?= $h->e($return) ?>"><?= $h->t('Cancel') ?></a></p>
    </div>
</div>
