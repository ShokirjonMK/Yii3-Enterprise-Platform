<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $k
 */
?>
<dl class="dl runtime-dl">
    <dt><?= $h->t('Key') ?></dt><dd class="mono small runtime-key"><?= $h->e($k['key']) ?></dd>
    <dt><?= $h->t('Namespace') ?></dt><dd><?= $h->e($k['namespace']) ?></dd>
    <dt><?= $h->t('Type') ?></dt><dd><?= $h->e($k['type']) ?></dd>
    <dt><?= $h->t('TTL') ?></dt><dd><?= $k['ttl'] === -1 ? $h->t('no expiry') : (int) $k['ttl'] . ' s' ?></dd>
    <dt><?= $h->t('Memory') ?></dt><dd><?= $k['size'] === null ? '—' : $h->e($h->bytes($k['size'])) ?></dd>
    <?php if ($k['elements'] !== null): ?><dt><?= $h->t('Elements') ?></dt><dd><?= (int) $k['elements'] ?></dd><?php endif ?>
    <dt><?= $h->t('Value') ?></dt>
    <dd><?php if ($k['sensitive']): ?><?= $h->badge('blocked', $h->tr('hidden — sensitive key')) ?><?php elseif ($k['preview'] === null): ?><span class="muted"><?= $h->t('not shown for this type') ?></span><?php else: ?><pre><?= $h->e($k['preview']) ?></pre><span class="small muted"><?= $h->t('first 512 bytes, redacted') ?></span><?php endif ?></dd>
</dl>
