<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $url
 * @var int $ttl
 * @var string $id
 */
?>
<div class="alert alert--info runtime-presigned">
    <div>
        <strong><?= $h->t('Pre-signed URL (valid {n} s)', ['n' => $ttl]) ?></strong>
        <input type="text" class="mono" readonly value="<?= $h->e($url) ?>" aria-label="<?= $h->t('Pre-signed URL') ?>">
        <div class="small"><?= $h->t('Anyone with this link can download the object until it expires. The S3 endpoint must be reachable from the client.') ?></div>
    </div>
</div>
