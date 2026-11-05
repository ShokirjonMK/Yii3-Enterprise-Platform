<?php

declare(strict_types=1);

/**
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<array<string,mixed>> $runs
 */
?>
<?php if ($runs === []): ?>
    <div class="empty"><?= $h->t('No runs recorded yet.') ?></div>
<?php else: ?>
<div class="table-wrap"><table class="table table--compact">
    <thead><tr><th><?= $h->t('Schedule') ?></th><th><?= $h->t('Scheduled for') ?></th><th><?= $h->t('Result') ?></th><th><?= $h->t('Job') ?></th><th><?= $h->t('Host') ?></th><th><?= $h->t('Message') ?></th></tr></thead>
    <tbody>
    <?php foreach ($runs as $r): ?>
        <tr>
            <td class="mono small"><?= $h->e($r['code']) ?></td>
            <td class="nowrap small"><?= $h->datetime($r['scheduled_for'], 'Y-m-d H:i:s') ?></td>
            <td><?= $h->badge((string) $r['status']) ?></td>
            <td class="small"><?php if ($r['job_id']): ?><a class="mono" href="<?= $h->url('admin.jobs.view', ['id' => $r['job_id']]) ?>"><?= $h->e(substr((string) $r['job_id'], 0, 8)) ?></a> <?= $r['job_status'] ? $h->badge((string) $r['job_status']) : '' ?><?php else: ?>—<?php endif ?></td>
            <td class="small muted"><?= $h->e($r['host']) ?></td>
            <td class="small"><?= $h->e($r['message']) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table></div>
<?php endif ?>
