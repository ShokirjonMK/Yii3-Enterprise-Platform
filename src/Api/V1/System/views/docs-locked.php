<?php

declare(strict_types=1);

/**
 * /api/docs when the documentation is locked: enter an access password issued by a superadmin,
 * or sign in with an account whose role has api.docs.view.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var array<string, list<string>> $errors
 */

$this->setTitle($h->tr('API documentation'));
$err = $errors['password'] ?? [];
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('API documentation') ?></h1>
        <p><?= $h->t('The API documentation is private. Enter the access password you received from the administrator, or sign in with an account that is allowed to view it.') ?></p>
        <form method="post" action="/api/docs/unlock" class="form" data-docs-unlock>
            <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
            <div class="field<?= $err ? ' field--invalid' : '' ?>">
                <label for="docs-password"><?= $h->t('Access password') ?></label>
                <input id="docs-password" name="password" type="password" autocomplete="off" required maxlength="64" autofocus
                       <?= $err ? 'aria-invalid="true" aria-describedby="docs-password-err"' : '' ?>>
                <?php foreach ($err as $e): ?><div class="field__error" id="docs-password-err"><?= $h->t($e) ?></div><?php endforeach ?>
            </div>
            <button type="submit" class="btn btn--primary"><?= $h->t('Open documentation') ?></button>
        </form>
        <p class="mt"><a href="<?= $h->url('auth.login', [], ['return' => '/api/docs']) ?>"><?= $h->t('Sign in instead') ?></a></p>
    </div>
</div>
