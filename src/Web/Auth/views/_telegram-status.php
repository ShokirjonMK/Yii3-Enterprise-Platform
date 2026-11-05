<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $status pending | linked | expired
 */
?>
<?php if ($status === 'linked'): ?>
    <div class="alert alert--success" role="status"><?= $h->icon('check') ?> <?= $h->t('Telegram is connected.') ?></div>
<?php elseif ($status === 'expired'): ?>
    <div class="alert alert--warning" role="status"><?= $h->t('The link has expired. Press "Connect Telegram" again.') ?> <a href="<?= $h->url('auth.mfa.setup', [], ['method' => 'telegram']) ?>"><?= $h->t('Retry') ?></a></div>
<?php else: ?>
    <p class="small muted" role="status"><span class="spinner" aria-hidden="true"></span> <?= $h->t('Waiting for you to press START in Telegram…') ?></p>
<?php endif ?>
