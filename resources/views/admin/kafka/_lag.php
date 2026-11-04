<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $group
 * @var list<array{topic:string, partition:int, committed:int, high:int, lag:int}> $rows
 * @var string|null $error
 */

$total = array_sum(array_column($rows, 'lag'));
?>
<?php if ($error !== null): ?>
    <div class="card__body small"><?= $error === 'Not configured' ? $h->badge('not_configured', $h->tr('Not configured')) : $h->badge('error', $h->tr('Lag unavailable')) . ' <span class="mono">' . $h->e($error) . '</span>' ?></div>
<?php elseif ($rows === []): ?>
    <div class="card__body small muted"><?= $h->t('No partitions (topics missing on the cluster?).') ?></div>
<?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= $h->t('Topic') ?></th><th class="num"><?= $h->t('Partition') ?></th><th class="num"><?= $h->t('Committed') ?></th><th class="num"><?= $h->t('High watermark') ?></th><th class="num"><?= $h->t('Lag') ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><td class="mono small"><?= $h->e($r['topic']) ?></td><td class="num"><?= (int) $r['partition'] ?></td><td class="num"><?= $r['committed'] < 0 ? '—' : (int) $r['committed'] ?></td><td class="num"><?= (int) $r['high'] ?></td><td class="num"><strong><?= (int) $r['lag'] ?></strong></td></tr>
        <?php endforeach ?>
        </tbody>
        <tfoot><tr><td colspan="4" class="right small muted"><?= $h->t('Total lag · updated {t}', ['t' => date('H:i:s')]) ?></td><td class="num"><strong><?= (int) $total ?></strong></td></tr></tfoot>
    </table></div>
<?php endif ?>
