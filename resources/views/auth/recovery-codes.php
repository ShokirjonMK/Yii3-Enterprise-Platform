<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<string> $codes
 * @var string $next
 */

$this->setTitle($h->tr('Recovery codes'));
?>
<div class="card">
    <div class="card__body">
        <h1><?= $h->t('Save your recovery codes') ?></h1>
        <div class="alert alert--warning"><?= $h->t('Each code can be used once if you lose access to your authenticator. They will not be shown again.') ?></div>
        <div class="recovery-codes mb">
            <?php foreach ($codes as $c): ?><code><?= $h->e($c) ?></code><?php endforeach ?>
        </div>
        <div class="row">
            <button type="button" class="btn" data-copy="<?= $h->e(implode("\n", $codes)) ?>"><?= $h->t('Copy') ?></button>
            <a class="btn btn--primary" href="<?= $h->url($next) ?>"><?= $h->t('I have saved them — continue') ?></a>
        </div>
    </div>
</div>
