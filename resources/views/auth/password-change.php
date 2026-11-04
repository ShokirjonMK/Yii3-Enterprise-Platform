<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var array<string, list<string>>|null $errors
 * @var bool $forced
 */

$this->setTitle($h->tr('Change password'));
$errors ??= [];
$field = static function (string $name, string $label, string $autocomplete) use ($h, $errors): string {
    $err = $errors[$name] ?? [];
    $html = '<div class="field' . ($err ? ' field--invalid' : '') . '"><label for="' . $name . '">' . $h->t($label) . '</label>'
        . '<input id="' . $name . '" name="' . $name . '" type="password" autocomplete="' . $autocomplete . '" required maxlength="256"'
        . ($err ? ' aria-invalid="true" aria-describedby="' . $name . '-err"' : '') . '>';
    foreach ($err as $e) {
        $html .= '<div class="field__error" id="' . $name . '-err">' . $h->t($e) . '</div>';
    }
    return $html . '</div>';
};
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('Change password') ?></h1>
        <?php if ($forced): ?>
            <div class="alert alert--warning"><?= $h->t('You must set a new password before continuing.') ?></div>
        <?php endif ?>
        <form method="post" action="<?= $h->url('auth.password.change') ?>" class="form">
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <?= $field('current_password', 'Current password', 'current-password') ?>
            <?= $field('new_password', 'New password', 'new-password') ?>
            <?= $field('new_password_confirm', 'Repeat new password', 'new-password') ?>
            <p class="field__hint"><?= $h->t('At least 12 characters with upper- and lower-case letters, a digit and a symbol. Must differ from your last 5 passwords.') ?></p>
            <button type="submit" class="btn btn--primary"><?= $h->t('Change password') ?></button>
        </form>
        <form method="post" action="<?= $h->url('auth.logout') ?>" class="mt"><input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= $h->t('Sign out') ?></button></form>
    </div>
</div>
