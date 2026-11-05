<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{ok:bool, status:string, http_status:?int, duration_ms:int, error:?string, error_class:?string} $result
 */
?>
<div id="test-result" class="alert alert--<?= $result['ok'] ? 'success' : ($result['status'] === 'unconfigured' ? 'warning' : 'error') ?>" data-test-status="<?= $h->e($result['status']) ?>">
    <?php if ($result['ok']): ?>
        <?= $h->t('Connection OK — HTTP {code} in {ms} ms.', ['code' => (string) $result['http_status'], 'ms' => (string) $result['duration_ms']]) ?>
    <?php else: ?>
        <div><strong><?= $result['status'] === 'unconfigured' ? $h->t('Not configured') : $h->t('Connection failed') ?></strong>
            <?php if ($result['error_class']): ?><span class="tag"><?= $h->e($result['error_class']) ?></span><?php endif ?>
            <div class="small"><?= $h->e($result['error']) ?></div></div>
    <?php endif ?>
</div>
