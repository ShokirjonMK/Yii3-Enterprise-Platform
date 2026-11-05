<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array{ok:bool, latency_ms:int, version:?string, error:?string}|null $result
 */
?>
<span data-db-status="<?= $result === null ? 'disabled' : ($result['ok'] ? 'ok' : 'failed') ?>">
<?php if ($result === null): ?><?= $h->badge('disabled') ?>
<?php elseif ($result['ok']): ?><?= $h->badge('ok', $h->tr('online')) ?> <span class="small muted"><?= (int) $result['latency_ms'] ?> ms · <?= $h->e((string) $result['version']) ?></span>
<?php else: ?><?= $h->badge('failed', $h->tr('unreachable')) ?> <span class="small muted" title="<?= $h->e((string) $result['error']) ?>"><?= $h->e(mb_strimwidth((string) $result['error'], 0, 80, '…')) ?></span>
<?php endif ?>
</span>
