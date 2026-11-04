<?php

declare(strict_types=1);

/**
 * Job rows (shared by "Queues & jobs" and "Failed & dead").
 *
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $items
 * @var bool $selectable
 */

$selectable ??= false;
?>
<?php foreach ($items as $j): ?>
    <tr>
        <?php if ($selectable): ?><td><input type="checkbox" name="ids[]" value="<?= $h->e($j['id']) ?>" form="bulk-retry" aria-label="<?= $h->t('Select') ?>"></td><?php endif ?>
        <td class="nowrap"><a class="mono" href="<?= $h->url('admin.jobs.view', ['id' => $j['id']]) ?>"><?= $h->e(substr((string) $j['id'], 0, 8)) ?>…</a></td>
        <td><code><?= $h->e($j['type']) ?></code></td>
        <td><span class="tag"><?= $h->e($j['queue']) ?></span></td>
        <td><?= $h->badge((string) $j['status']) ?><?php if (\App\Shared\Infrastructure\Database\Sql::bool($j['cancel_requested']) && $j['status'] === 'running'): ?> <span class="small muted"><?= $h->t('cancelling') ?></span><?php endif ?></td>
        <td class="num"><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></td>
        <td class="num"><?= (int) $j['priority'] ?></td>
        <td class="nowrap small" title="<?= $h->datetime($j['created_at'], 'Y-m-d H:i:s') ?>"><?= $h->ago($j['created_at']) ?></td>
        <td class="nowrap small"><?= $j['status'] === 'pending' ? $h->datetime($j['available_at'], 'm-d H:i:s') : $h->ago($j['finished_at'] ?? $j['started_at']) ?></td>
        <td class="small runtime-error"><?= $h->e(mb_strimwidth((string) ($j['last_error'] ?? ''), 0, 140, '…')) ?></td>
    </tr>
<?php endforeach ?>
