<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<string> $runs
 * @var string|null $error
 */
?>
<div id="cron-preview" class="field__hint" aria-live="polite">
    <?php if ($error !== null): ?>
        <span class="field__error"><?= $h->t($error) ?></span>
    <?php else: ?>
        <strong><?= $h->t('Next runs:') ?></strong>
        <?php foreach ($runs as $r): ?><span class="tag"><?= $h->e($r) ?></span><?php endforeach ?>
    <?php endif ?>
</div>
