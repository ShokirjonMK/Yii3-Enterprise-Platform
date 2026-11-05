<?php

declare(strict_types=1);

/**
 * Live part of a Next steps run: polled every second (outerHTML swap) while queued/running; the final
 * response has no trigger, so polling stops by itself.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $run
 */

$active = in_array($run['status'], ['queued', 'running'], true);
$secs = static fn(?int $ms): string => $ms === null ? '—' : sprintf('%.1f s', $ms / 1000);
$total = $run['started_at'] !== null && $run['finished_at'] !== null
    ? (int) round((strtotime((string) $run['finished_at']) - strtotime((string) $run['started_at'])) * 1000) : null;
?>
<div id="run-live"<?php if ($active): ?> hx-get="<?= $h->url('admin.devtools.gii.runs.live', ['id' => $run['id']]) ?>" hx-trigger="every 1s" hx-swap="outerHTML"<?php endif ?>>
    <div class="card">
        <div class="card__head">
            <h2><?= $h->t('Status') ?> <?= $h->badge((string) $run['status']) ?><?php if ($active): ?> <span class="spinner" aria-hidden="true"></span><?php endif ?></h2>
            <span class="small muted">
                <?= $h->t('mode') ?>: <?= $h->e($run['mode'] === 'queue' ? $h->tr('queue worker') : $h->tr('inline')) ?> ·
                <?= $h->t('created') ?> <?= $h->datetime($run['created_at']) ?>
                <?php if ($run['finished_at'] !== null): ?> · <?= $h->t('duration') ?> <?= $h->e($secs($total)) ?><?php endif ?>
            </span>
        </div>
        <?php if ($run['status'] === 'queued' && $run['mode'] === 'queue'): ?>
            <div class="card__body small muted"><?= $h->t('Waiting for a queue worker to pick the job up…') ?></div>
        <?php endif ?>
        <ol class="run-steps">
            <?php foreach ($run['steps'] as $s): ?>
                <li class="run-steps__item run-steps__item--<?= $h->e((string) $s['status']) ?>">
                    <?= $h->badge((string) $s['status']) ?>
                    <strong><?= $h->t((string) $s['label']) ?></strong>
                    <code><?= $h->e((string) $s['cli']) ?></code>
                    <span class="small muted">
                        <?php if ($s['exit_code'] !== null): ?><?= $h->t('exit code') ?> <?= (int) $s['exit_code'] ?> · <?php endif ?>
                        <?= $h->e($secs($s['duration_ms'] !== null ? (int) $s['duration_ms'] : null)) ?>
                    </span>
                </li>
            <?php endforeach ?>
        </ol>
    </div>

    <div class="card mt">
        <div class="card__head"><h2><?= $h->t('Console output') ?></h2><span class="small muted"><?= $h->t('{n} lines', ['n' => substr_count((string) $run['log'], "\n")]) ?></span></div>
        <div class="run-log"><pre class="gii-code run-log__pre"><?= $h->e((string) $run['log']) ?></pre></div>
    </div>

    <?php if ($run['status'] === 'succeeded'): ?>
        <div class="alert alert--info mt"><?= $h->icon('info') ?>
            <div><?= $h->t('Done. Long-running processes (queue worker, Kafka consumers, scheduler) load job handlers and consumer groups at start — restart them to pick up the new module: docker compose restart worker consumer scheduler (Kubernetes: rollout restart).') ?></div>
        </div>
    <?php elseif ($run['status'] === 'failed'): ?>
        <div class="alert alert--error mt"><?= $h->icon('alert') ?>
            <div><?= $h->t('The run failed — see the console output above. Fix the cause and start the steps again (completed steps are safe to repeat).') ?></div>
        </div>
    <?php endif ?>
</div>
