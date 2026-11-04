<?php

declare(strict_types=1);

/**
 * One-time display of a generated temporary password (never stored in plain text, never shown again).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $id
 * @var string $username
 * @var string $password
 * @var string $reason created|reset
 */

$this->setTitle($h->tr('Temporary password'));
$this->setParameter('breadcrumbs', [$h->tr('Users') => $h->url('admin.users'), $username => $h->url('admin.users.view', ['id' => $id]), $h->tr('Temporary password')]);
?>
<div class="page-head"><div class="page-head__titles">
    <h1><?= $h->t('Temporary password') ?></h1>
    <p class="page-head__sub"><?= $reason === 'created' ? $h->t('User {u} was created.', ['u' => $username]) : $h->t('The password of {u} was reset and all sessions were terminated.', ['u' => $username]) ?></p>
</div></div>

<div class="card id-secret">
    <div class="card__body stack">
        <div class="alert alert--warning" role="alert"><?= $h->icon('alert') ?> <span><?= $h->t('Copy this password now and hand it over through a secure channel. It is shown only once and cannot be retrieved later.') ?></span></div>
        <div class="id-secret__value">
            <code class="mono" data-temporary-password><?= $h->e($password) ?></code>
            <button type="button" class="btn btn--sm" data-copy="<?= $h->e($password) ?>"><?= $h->icon('file') ?> <?= $h->t('Copy') ?></button>
        </div>
        <p class="small muted"><?= $h->t('The user must change this password at the next sign-in.') ?></p>
        <div><a class="btn btn--primary" href="<?= $h->url('admin.users.view', ['id' => $id]) ?>"><?= $h->t('Done') ?></a></div>
    </div>
</div>
