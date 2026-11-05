<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array|null $row
 * @var array $values
 * @var array<string,list<string>> $errors
 * @var list<string> $jobTypes
 * @var string $csrf
 */
$isNew = $row === null;
$this->setTitle($isNew ? $h->tr('New webhook endpoint') : $h->tr('Edit webhook {code}', ['code' => $row['code']]));
$this->setParameter('breadcrumbs', [$h->tr('Integrations') => $h->url('admin.integrations'), $h->tr('Inbound webhooks') => $h->url('admin.integrations.webhooks'), $isNew ? $h->tr('New') : $row['code']]);
$v = static fn(string $k, mixed $d = '') => $values[$k] ?? $d;
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field__error">' . $h->e(implode(' ', $errors[$k])) . '</div>' : '';
$cls = static fn(string $k): string => isset($errors[$k]) ? 'field field--invalid' : 'field';
$text = static fn(string $name, string $label, string $placeholder = '', string $hint = ''): string => '<div class="' . $cls($name) . '"><label for="w-' . $name . '">' . $h->t($label) . '</label><input type="text" id="w-' . $name . '" name="' . $name . '" value="' . $h->e($v($name)) . '" placeholder="' . $h->e($placeholder) . '">' . ($hint !== '' ? '<div class="field__hint">' . $h->t($hint) . '</div>' : '') . $err($name) . '</div>';
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $isNew ? $h->t('New webhook endpoint') : $h->t('Edit webhook endpoint') ?></h1></div></div>
<?php if ($errors !== []): ?><div class="alert alert--error"><?= $h->t('Please correct the highlighted fields.') ?></div><?php endif ?>
<form class="card" method="post" action="<?= $isNew ? $h->url('admin.integrations.webhooks.store') : $h->url('admin.integrations.webhooks.update', ['id' => $row['id']]) ?>">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <div class="card__body form form--2">
        <div class="<?= $cls('code') ?>"><label for="w-code"><?= $h->t('Code') ?></label><input type="text" id="w-code" name="code" value="<?= $h->e($v('code')) ?>" <?= $isNew ? 'required' : 'readonly' ?>><div class="field__hint"><?= $h->t('Part of the URL') ?>: <span class="mono">/webhooks/&lt;code&gt;</span></div><?= $err('code') ?></div>
        <?= $text('name', 'Name') ?>
        <div class="field span-2"><label for="w-desc"><?= $h->t('Description') ?></label><input type="text" id="w-desc" name="description" value="<?= $h->e($v('description')) ?>"></div>
        <?= $text('signature_header', 'Signature header', 'X-Signature', 'Value: sha256=<hex HMAC of "timestamp.body">') ?>
        <?= $text('timestamp_header', 'Timestamp header', 'X-Timestamp', 'Unix seconds; part of the signed material.') ?>
        <?= $text('id_header', 'Event id header', 'X-Webhook-Id', 'Idempotency key; falls back to body "id"/"event_id", then the body hash.') ?>
        <div class="<?= $cls('tolerance_seconds') ?>"><label for="w-tol"><?= $h->t('Timestamp tolerance (s)') ?></label><input type="number" id="w-tol" name="tolerance_seconds" min="30" max="3600" value="<?= $h->e($v('tolerance_seconds', 300)) ?>"><?= $err('tolerance_seconds') ?></div>
        <div class="<?= $cls('job_type') ?>"><label for="w-job"><?= $h->t('Job type') ?></label>
            <select id="w-job" name="job_type">
                <option value=""><?= $h->t('platform.webhooks.ack (store + acknowledge)') ?></option>
                <?php foreach ($jobTypes as $t): ?><option value="<?= $h->e($t) ?>"<?= $v('job_type') === $t ? ' selected' : '' ?>><?= $h->e($t) ?></option><?php endforeach ?>
            </select><div class="field__hint"><?= $h->t('Handler that processes accepted events asynchronously.') ?></div><?= $err('job_type') ?></div>
        <?php if ($isNew): ?><label class="check"><input type="checkbox" name="enabled" value="1"<?= $v('enabled') ? ' checked' : '' ?>> <?= $h->t('Enabled immediately') ?></label><?php endif ?>
    </div>
    <div class="card__foot">
        <a class="btn btn--ghost" data-back href="<?= $h->url('admin.integrations.webhooks') ?>"><?= $h->t('Cancel') ?></a>
        <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save') ?></button>
    </div>
</form>
