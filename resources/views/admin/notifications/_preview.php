<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{subject:string, body:string, source:string} $preview
 * @var string|null $error
 */
?>
<div class="card__body" data-preview-source="<?= $h->e($preview['source']) ?>">
    <?php if ($error): ?><div class="alert alert--warning"><?= $h->t($error) ?></div><?php endif ?>
    <div class="small muted"><?= $preview['source'] === 'draft' ? $h->t('Draft rendering') : $h->t('Stored template (Notifier::render with locale/channel fallback)') ?></div>
    <h3 class="mt"><?= $h->e($preview['subject']) ?></h3>
    <pre data-preview-body><?= $h->e($preview['body']) ?></pre>
</div>
