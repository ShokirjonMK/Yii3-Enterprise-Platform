<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string|null $csrf
 * @var array<string,mixed>|null $schedule
 * @var array<string,mixed> $values
 * @var array<string, list<string>> $errors
 * @var list<string> $types
 * @var list<string> $queues
 * @var list<string> $preview
 * @var string|null $previewError
 * @var list<array<string,mixed>> $runs
 */

use App\Shared\Infrastructure\Database\Sql;

$isNew = $schedule === null;
$title = $isNew ? $h->tr('New schedule') : $h->tr('Edit schedule {code}', ['code' => $schedule['code']]);
$this->setTitle($title);
$this->setParameter('breadcrumbs', [$h->tr('Jobs & Scheduler') => $h->url('admin.jobs'), $h->tr('Schedules') => $h->url('admin.jobs.schedules'), $isNew ? $h->tr('New') : (string) $schedule['code']]);
$action = $isNew ? $h->url('admin.jobs.schedules.store') : $h->url('admin.jobs.schedules.update', ['id' => $schedule['id']]);
$err = static function (string $field) use ($errors, $h): string {
    $out = '';
    foreach ($errors[$field] ?? [] as $m) {
        $out .= '<div class="field__error">' . $h->t($m) . '</div>';
    }
    return $out;
};
$inv = static fn(string $f): string => isset($errors[$f]) ? ' field--invalid' : '';
$enabled = is_bool($values['enabled'] ?? null) ? $values['enabled'] : Sql::bool($values['enabled'] ?? false);
?>
<div class="page-head">
    <div class="page-head__titles"><h1><?= $h->e($title) ?></h1></div>
</div>
<?= $this->render('./_tabs', ['active' => 'admin.jobs.schedules']) ?>

<?php if ($errors !== []): ?><div class="alert alert--error"><?= $h->t('Please correct the highlighted fields.') ?></div><?php endif ?>

<div class="grid grid--sidebar">
<form class="card" method="post" action="<?= $h->e($action) ?>">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="card__body form form--2">
        <div class="field<?= $inv('code') ?>">
            <label for="code"><?= $h->t('Code') ?></label>
            <input type="text" id="code" name="code" value="<?= $h->e($values['code'] ?? '') ?>" <?= $isNew ? 'required pattern="[a-z0-9][a-z0-9_.\-]{1,95}"' : 'readonly' ?>>
            <?= $err('code') ?>
        </div>
        <div class="field<?= $inv('name') ?>">
            <label for="name"><?= $h->t('Name') ?></label>
            <input type="text" id="name" name="name" value="<?= $h->e($values['name'] ?? '') ?>" maxlength="255">
        </div>
        <div class="field<?= $inv('cron_expression') ?>">
            <label for="cron_expression"><?= $h->t('Cron expression') ?></label>
            <input type="text" id="cron_expression" name="cron_expression" class="mono" value="<?= $h->e($values['cron_expression'] ?? '') ?>" required
                   hx-get="<?= $h->url('admin.jobs.schedules.preview') ?>" hx-include="#cron_expression, #timezone" hx-trigger="keyup changed delay:400ms, change" hx-target="#cron-preview" hx-swap="outerHTML">
            <?= $err('cron_expression') ?>
            <?= $this->render('./_preview', ['runs' => $preview, 'error' => $previewError]) ?>
        </div>
        <div class="field<?= $inv('timezone') ?>">
            <label for="timezone"><?= $h->t('Time zone') ?></label>
            <input type="text" id="timezone" name="timezone" value="<?= $h->e($values['timezone'] ?? 'Asia/Tashkent') ?>" required
                   hx-get="<?= $h->url('admin.jobs.schedules.preview') ?>" hx-include="#cron_expression, #timezone" hx-trigger="change" hx-target="#cron-preview" hx-swap="outerHTML">
            <?= $err('timezone') ?>
        </div>
        <div class="field<?= $inv('job_type') ?>">
            <label for="job_type"><?= $h->t('Job type') ?></label>
            <select id="job_type" name="job_type" required>
                <option value=""><?= $h->t('Select…') ?></option>
                <?php foreach ($types as $t): ?><option value="<?= $h->e($t) ?>"<?= ($values['job_type'] ?? '') === $t ? ' selected' : '' ?>><?= $h->e($t) ?></option><?php endforeach ?>
            </select>
            <?= $err('job_type') ?>
        </div>
        <div class="field">
            <label for="queue"><?= $h->t('Queue') ?></label>
            <input type="text" id="queue" name="queue" value="<?= $h->e($values['queue'] ?? 'default') ?>" list="queue-list" pattern="[a-z0-9_\-]{1,64}">
            <datalist id="queue-list"><?php foreach ($queues as $q): ?><option value="<?= $h->e($q) ?>"></option><?php endforeach ?></datalist>
        </div>
        <div class="field">
            <label for="overlap"><?= $h->t('Overlap') ?></label>
            <select id="overlap" name="overlap">
                <option value="skip"<?= ($values['overlap'] ?? 'skip') === 'skip' ? ' selected' : '' ?>><?= $h->t('Skip while previous run is pending/running') ?></option>
                <option value="allow"<?= ($values['overlap'] ?? '') === 'allow' ? ' selected' : '' ?>><?= $h->t('Allow overlapping runs') ?></option>
            </select>
        </div>
        <div class="field">
            <span class="label"><?= $h->t('State') ?></span>
            <label class="check"><input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>> <?= $h->t('Enabled') ?></label>
        </div>
        <div class="field span-2<?= $inv('payload') ?>">
            <label for="payload"><?= $h->t('Payload (JSON object)') ?></label>
            <textarea id="payload" name="payload" class="code"><?= $h->e($values['payload'] ?? '{}') ?></textarea>
            <?= $err('payload') ?>
            <div class="field__hint"><?= $h->t('Do not put secrets into payloads — reference settings instead.') ?></div>
        </div>
    </div>
    <div class="card__foot">
        <a class="btn btn--ghost" href="<?= $h->url('admin.jobs.schedules') ?>"><?= $h->t('Cancel') ?></a>
        <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save') ?></button>
    </div>
</form>
<div class="card">
    <div class="card__head"><h2><?= $h->t('Cron syntax') ?></h2></div>
    <div class="card__body small">
        <p><code>minute hour day-of-month month day-of-week</code></p>
        <ul>
            <li><code>*/5 * * * *</code> — <?= $h->t('every 5 minutes') ?></li>
            <li><code>0 3 * * *</code> — <?= $h->t('daily at 03:00') ?></li>
            <li><code>0 9 * * 1-5</code> — <?= $h->t('weekdays at 09:00') ?></li>
            <li><code>@hourly</code>, <code>@daily</code>, <code>@weekly</code></li>
        </ul>
    </div>
</div>
</div>

<?php if (!$isNew): ?>
<div class="card mt">
    <div class="card__head"><h2><?= $h->t('Run history') ?></h2></div>
    <?= $this->render('./_runs', ['runs' => $runs]) ?>
</div>
<?php endif ?>
