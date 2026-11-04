<?php

declare(strict_types=1);

/**
 * Template: admin report status view (resources/views/admin/<module>/report.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

/**
 * Admin · {{label}} report status (auto-refresh while the job runs) and download.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $report
 * @var bool $storageConfigured
 */

$this->setTitle($h->tr('{{label}} report'));
$this->setParameter('breadcrumbs', [$h->tr('{{label}}') => $h->url('admin.{{code}}'), $h->tr('Report')]);
$running = in_array($report['status'], ['pending', 'running'], true);
$url = $h->url('admin.{{code}}.report', ['id' => $report['id']]);
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $h->t('{{label}} report') ?></h1></div></div>
<?php if (!$storageConfigured): ?>
    <div class="alert alert--warning"><?= $h->t('Object storage: Not configured.') ?></div>
<?php endif ?>
<div class="card"<?= $running ? ' hx-get="' . $h->e($url) . '" hx-trigger="every 3s" hx-target="#main" hx-select="#main" hx-swap="outerHTML"' : '' ?>>
    <div class="card__body">
        <dl class="dl">
            <dt><?= $h->t('Status') ?></dt><dd><?= $h->badge($report['status'] === 'done' ? 'completed' : (string) $report['status'], $h->tr((string) $report['status'])) ?></dd>
            <dt><?= $h->t('Job') ?></dt><dd class="mono small"><?= $h->e($report['job_id'] ?? '—') ?> <?= $h->e($report['job_status'] ?? '') ?></dd>
            <dt><?= $h->t('Rows') ?></dt><dd><?= $report['rows_count'] === null ? '—' : (int) $report['rows_count'] ?></dd>
            <dt><?= $h->t('Size') ?></dt><dd><?= $report['size_bytes'] === null ? '—' : $h->bytes($report['size_bytes']) ?></dd>
            <dt><?= $h->t('Requested') ?></dt><dd><?= $h->datetime($report['created_at']) ?></dd>
            <dt><?= $h->t('Finished') ?></dt><dd><?= $h->datetime($report['finished_at']) ?></dd>
            <?php if ($report['error']): ?><dt><?= $h->t('Error') ?></dt><dd class="mono small"><?= $h->e($report['error']) ?></dd><?php endif ?>
        </dl>
        <?php if ($report['status'] === 'done'): ?>
            <a class="btn btn--primary" href="<?= $h->url('admin.{{code}}.report.download', ['id' => $report['id']]) ?>"><?= $h->icon('download') ?> <?= $h->t('Download CSV') ?></a>
        <?php endif ?>
    </div>
</div>

PHP);
