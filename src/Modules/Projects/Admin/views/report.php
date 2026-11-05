<?php

declare(strict_types=1);

/**
 * Admin · Projects report job status (auto-refresh while pending/running).
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $report
 * @var bool $storageConfigured
 */

$this->setTitle($h->tr('Projects report'));
$this->setParameter('breadcrumbs', [$h->tr('Projects') => $h->url('admin.projects'), $h->tr('Report')]);
$pending = in_array($report['status'], ['pending', 'running'], true);
$self = $h->url('admin.projects.report', ['id' => $report['id']]);
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->t('Projects report') ?></h1><p class="page-head__sub mono"><?= $h->e($report['id']) ?></p></div>
    <div class="page-head__actions">
        <?php if ($report['status'] === 'done'): ?>
            <a class="btn btn--primary" href="<?= $h->url('admin.projects.report.download', ['id' => $report['id']]) ?>"><?= $h->icon('download') ?> <?= $h->t('Download CSV') ?></a>
        <?php endif ?>
    </div>
</div>
<div class="card" id="report-status"<?= $pending ? ' hx-get="' . $h->e($self) . '" hx-trigger="every 3s" hx-target="#main" hx-select="#main" hx-swap="outerHTML"' : '' ?>>
    <div class="card__body">
        <?php if (!$storageConfigured): ?>
            <div class="alert alert--warning"><?= $h->t('Object storage is not configured: reports cannot be stored.') ?></div>
        <?php endif ?>
        <dl class="dl">
            <dt><?= $h->t('Status') ?></dt><dd><?= $h->badge($report['status'] === 'done' ? 'completed' : $report['status'], $h->tr($report['status'])) ?></dd>
            <dt><?= $h->t('Job') ?></dt><dd>
                <?php $jobUrl = $report['job_id'] ? $h->urlOrNull('admin.jobs.view', ['id' => $report['job_id']]) : null; ?>
                <?php if ($jobUrl): ?><a class="mono" href="<?= $h->e($jobUrl) ?>"><?= $h->e($report['job_id']) ?></a><?php else: ?><span class="mono"><?= $h->e($report['job_id'] ?? '—') ?></span><?php endif ?>
                <?= $report['job_status'] ? $h->badge($report['job_status']) : '' ?> <?= $pending ? $h->e((int) $report['job_progress'] . '%') : '' ?>
            </dd>
            <dt><?= $h->t('Rows') ?></dt><dd><?= $report['rows_count'] === null ? '—' : (int) $report['rows_count'] ?></dd>
            <dt><?= $h->t('Size') ?></dt><dd><?= $report['size_bytes'] === null ? '—' : $h->bytes($report['size_bytes']) ?></dd>
            <dt><?= $h->t('Storage path') ?></dt><dd class="mono small"><?= $h->e($report['path'] ?? '—') ?></dd>
            <dt><?= $h->t('Requested') ?></dt><dd><?= $h->datetime($report['created_at']) ?></dd>
            <dt><?= $h->t('Finished') ?></dt><dd><?= $h->datetime($report['finished_at']) ?></dd>
            <?php if ($report['error']): ?><dt><?= $h->t('Error') ?></dt><dd class="field__error"><?= $h->e($report['error']) ?></dd><?php endif ?>
        </dl>
    </div>
</div>
