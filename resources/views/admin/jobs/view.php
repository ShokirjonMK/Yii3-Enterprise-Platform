<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var callable(string):bool $can
 * @var string|null $csrf
 * @var array<string,mixed> $job
 * @var list<array<string,mixed>> $attempts
 */

$short = substr((string) $job['id'], 0, 8);
$this->setTitle($h->tr('Job {id}', ['id' => $short]));
$this->setParameter('breadcrumbs', [$h->tr('Jobs & Scheduler') => $h->url('admin.jobs'), $short]);
$manage = $can('jobs.manage');
$progress = max(0, min(100, (int) $job['progress']));
$live = in_array($job['status'], ['pending', 'running'], true);
?>
<div id="job-detail"<?php if ($live): ?> hx-get="<?= $h->url('admin.jobs.view', ['id' => $job['id']]) ?>" hx-trigger="every 5s" hx-select="#job-detail" hx-target="this" hx-swap="outerHTML"<?php endif ?>>
<div class="page-head">
    <div class="page-head__titles">
        <h1><code><?= $h->e($job['type']) ?></code> <?= $h->badge((string) $job['status']) ?></h1>
        <p class="page-head__sub mono"><?= $h->e($job['id']) ?></p>
    </div>
    <?php if ($manage): ?>
    <div class="page-head__actions">
        <?php if (in_array($job['status'], ['failed', 'dead', 'cancelled'], true)): ?>
            <form method="post" action="<?= $h->url('admin.jobs.retry', ['id' => $job['id']]) ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <button class="btn btn--primary" type="submit"><?= $h->icon('refresh') ?> <?= $h->t('Retry') ?></button>
            </form>
        <?php endif ?>
        <?php if (in_array($job['status'], ['pending', 'running'], true)): ?>
            <form method="post" action="<?= $h->url('admin.jobs.cancel', ['id' => $job['id']]) ?>" data-confirm="<?= $h->t('Cancel this job? Running jobs stop cooperatively at the next checkpoint.') ?>">
                <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
                <button class="btn btn--danger" type="submit"><?= $h->icon('x') ?> <?= $h->t('Cancel') ?></button>
            </form>
        <?php endif ?>
    </div>
    <?php endif ?>
</div>

<div class="grid grid--sidebar">
    <div class="stack">
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Progress') ?></h2><span class="small muted"><?= $progress ?>%</span></div>
            <div class="card__body"><div class="bar<?= $job['status'] === 'dead' ? ' bar--bad' : ($job['status'] === 'succeeded' ? ' bar--ok' : '') ?>"><span class="w-<?= (int) (round($progress / 5) * 5) ?>"></span></div></div>
        </div>
        <?php if ($job['last_error'] !== null): ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Last error') ?></h2></div>
            <div class="card__body"><pre><?= $h->e($job['last_error']) ?></pre></div>
        </div>
        <?php endif ?>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Payload') ?></h2><span class="small muted"><?= $h->t('secrets redacted') ?></span></div>
            <div class="card__body"><pre><?= $h->json($job['payload']) ?></pre></div>
        </div>
        <div class="card">
            <div class="card__head"><h2><?= $h->t('Attempts') ?></h2></div>
            <?php if ($attempts === []): ?>
                <div class="empty"><?= $h->t('Not executed yet.') ?></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th>#</th><th><?= $h->t('Status') ?></th><th><?= $h->t('Worker') ?></th><th><?= $h->t('Started') ?></th><th class="num"><?= $h->t('Duration') ?></th><th><?= $h->t('Error') ?></th></tr></thead>
                <tbody>
                <?php foreach ($attempts as $a): ?>
                    <tr>
                        <td class="num"><?= (int) $a['attempt'] ?></td>
                        <td><?= $h->badge((string) $a['status']) ?></td>
                        <td class="mono small"><?= $h->e($a['worker_id']) ?></td>
                        <td class="nowrap small"><?= $h->datetime($a['started_at'], 'Y-m-d H:i:s') ?></td>
                        <td class="num small"><?= $a['duration_ms'] === null ? '—' : (int) $a['duration_ms'] . ' ms' ?></td>
                        <td class="small runtime-error"><?= $h->e(mb_strimwidth((string) ($a['error'] ?? ''), 0, 300, '…')) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </div>
    </div>
    <div class="card">
        <div class="card__head"><h2><?= $h->t('Details') ?></h2></div>
        <div class="card__body">
            <dl class="dl runtime-dl">
                <dt><?= $h->t('Queue') ?></dt><dd><span class="tag"><?= $h->e($job['queue']) ?></span></dd>
                <dt><?= $h->t('Attempts') ?></dt><dd><?= (int) $job['attempts'] ?> / <?= (int) $job['max_attempts'] ?></dd>
                <dt><?= $h->t('Priority') ?></dt><dd><?= (int) $job['priority'] ?></dd>
                <dt><?= $h->t('Timeout') ?></dt><dd><?= (int) $job['timeout_seconds'] ?> s</dd>
                <dt><?= $h->t('Backoff') ?></dt><dd><?= (int) $job['backoff_seconds'] ?> s</dd>
                <dt><?= $h->t('Created') ?></dt><dd><?= $h->datetime($job['created_at'], 'Y-m-d H:i:s') ?></dd>
                <dt><?= $h->t('Available at') ?></dt><dd><?= $h->datetime($job['available_at'], 'Y-m-d H:i:s') ?></dd>
                <dt><?= $h->t('Started') ?></dt><dd><?= $h->datetime($job['started_at'], 'Y-m-d H:i:s') ?></dd>
                <dt><?= $h->t('Finished') ?></dt><dd><?= $h->datetime($job['finished_at'], 'Y-m-d H:i:s') ?></dd>
                <dt><?= $h->t('Reserved by') ?></dt><dd class="mono small"><?= $h->e($job['reserved_by'] ?? '—') ?></dd>
                <dt><?= $h->t('Cancel requested') ?></dt><dd><?= App\Shared\Infrastructure\Database\Sql::bool($job['cancel_requested']) ? $h->t('yes') : $h->t('no') ?></dd>
                <dt><?= $h->t('Idempotency key') ?></dt><dd class="mono small"><?= $h->e($job['idempotency_key'] ?? '—') ?></dd>
                <dt><?= $h->t('Correlation id') ?></dt><dd class="mono small"><?= $h->e($job['correlation_id'] ?? '—') ?></dd>
                <dt><?= $h->t('Tenant') ?></dt><dd class="mono small"><?= $h->e($job['tenant_id'] ?? '—') ?></dd>
                <dt><?= $h->t('Created by') ?></dt><dd class="mono small"><?= $h->e($job['created_by'] ?? 'system') ?></dd>
            </dl>
        </div>
    </div>
</div>
</div>
