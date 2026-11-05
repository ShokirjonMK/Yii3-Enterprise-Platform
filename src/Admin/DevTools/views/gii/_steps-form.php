<?php

declare(strict_types=1);

/**
 * "Next steps" form (migrate:up, rbac:sync, Kafka topics) — on the Gii result and on the Next steps page.
 *
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $csrf
 * @var array{pending_migrations:list<string>, kafka_enabled:bool, kafka_missing:?list<string>, kafka_unsupported:?string, worker:bool, active:?string} $state
 * @var string $module
 * @var list<string>|null $selected
 */

use App\Platform\Gii\NextSteps\NextStepsRunner;

$pending = $state['pending_migrations'];
$missing = $state['kafka_missing'];
$selected ??= array_values(array_filter([
    'migrate',
    'rbac',
    $state['kafka_enabled'] && ($missing === null || $missing !== []) ? 'kafka' : null,
]));
$hint = [
    'migrate' => $pending === [] ? $h->tr('No pending migrations.') : $h->tr('{n} pending migration(s)', ['n' => count($pending)]),
    'rbac' => $h->tr('Adds the permissions and roles from config/common/permissions/*.php; overrides made in the panel are kept.'),
    'kafka' => !$state['kafka_enabled'] ? $h->tr('Kafka is disabled.')
        : ($state['kafka_unsupported'] !== null ? $state['kafka_unsupported']
        : ($missing === null ? $h->tr('Kafka is unreachable right now.')
        : ($missing === [] ? $h->tr('All registered topics exist.') : $h->tr('{n} topic(s) missing: {list}', ['n' => count($missing), 'list' => implode(', ', $missing)])))),
];
?>
<form method="post" action="<?= $h->url('admin.devtools.gii.steps.run') ?>" class="next-steps" data-confirm="<?= $h->t('Run the selected steps against the database and Kafka of this environment?') ?>">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <input type="hidden" name="module" value="<?= $h->e($module) ?>">
    <?php foreach (NextStepsRunner::STEPS as $key => $step): ?>
        <label class="next-steps__item">
            <input type="checkbox" name="steps[]" value="<?= $h->e($key) ?>"<?= in_array($key, $selected, true) ? ' checked' : '' ?>>
            <span>
                <strong><?= $h->t($step['label']) ?></strong> <code><?= $h->e($step['cli']) ?></code>
                <span class="small muted d-block"><?= $h->e($hint[$key]) ?></span>
                <?php if ($key === 'migrate' && $pending !== []): ?>
                    <span class="small mono d-block"><?= $h->e(implode(' · ', array_slice($pending, 0, 12))) ?><?= count($pending) > 12 ? ' …' : '' ?></span>
                <?php endif ?>
            </span>
        </label>
    <?php endforeach ?>
    <div class="row">
        <?php if ($state['active'] !== null): ?>
            <a class="btn" href="<?= $h->url('admin.devtools.gii.runs.view', ['id' => $state['active']]) ?>"><?= $h->icon('activity') ?> <?= $h->t('A run is in progress — open it') ?></a>
        <?php else: ?>
            <button class="btn btn--primary" type="submit"><?= $h->icon('play') ?> <?= $h->t('Run selected steps') ?></button>
        <?php endif ?>
        <span class="small muted">
            <?= $state['worker'] ? $h->t('Runs in the background on the queue worker; the log is shown live.') : $h->t('No queue worker is running — the steps run in this request and the log is shown when they finish.') ?>
            <?= $h->t('Requires re-authentication. Audited.') ?>
        </span>
    </div>
</form>
