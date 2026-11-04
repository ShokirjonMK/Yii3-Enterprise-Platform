<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var int $status
 * @var string $code
 * @var string $message
 * @var string $correlationId
 * @var App\Platform\Identity\Domain\Identity|null $identity
 */

$this->setTitle((string) $status);
$titles = [
    401 => 'Authentication required',
    403 => 'Access denied',
    404 => 'Page not found',
    409 => 'Conflict',
    422 => 'Invalid data',
    428 => 'Confirmation required',
    429 => 'Too many requests',
    503 => 'Service temporarily unavailable',
];
?>
<div class="card">
    <div class="card__body empty">
        <?= $h->icon($status === 403 ? 'lock' : 'alert') ?>
        <h1><?= $status ?> — <?= $h->t($titles[$status] ?? 'Error') ?></h1>
        <p><?= $h->t($message) ?></p>
        <p class="small muted"><?= $h->t('Reference') ?>: <span class="mono"><?= $h->e($correlationId) ?></span></p>
        <p><a class="btn" href="<?= $identity ? $h->url('home.redirect') : $h->url('auth.login') ?>"><?= $h->t('Go back') ?></a></p>
    </div>
</div>
