<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{ok:bool, latency_ms:int, version:?string, error:?string} $result
 */
?>
<div id="test-result" class="alert alert--<?= $result['ok'] ? 'success' : 'error' ?>" data-test-status="<?= $result['ok'] ? 'ok' : 'failed' ?>">
    <?php if ($result['ok']): ?>
        <?= $h->t('Connection OK — {version}, {ms} ms.', ['version' => (string) $result['version'], 'ms' => (string) $result['latency_ms']]) ?>
    <?php else: ?>
        <strong><?= $h->t('Connection failed') ?></strong><div class="small"><?= $h->e((string) $result['error']) ?></div>
    <?php endif ?>
</div>
