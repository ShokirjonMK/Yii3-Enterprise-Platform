<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array|null $row
 * @var array $values
 * @var array<string,list<string>> $errors
 * @var string $csrf
 */
$isNew = $row === null;
$this->setTitle($isNew ? $h->tr('New integration') : $h->tr('Edit {code}', ['code' => $row['code']]));
$this->setParameter('breadcrumbs', [$h->tr('Integrations') => $h->url('admin.integrations')] + ($isNew ? [] : [$row['code'] => $h->url('admin.integrations.show', ['id' => $row['id']])]) + [$isNew ? $h->tr('New') : $h->tr('Edit')]);
$v = static fn(string $k, mixed $d = '') => $values[$k] ?? $d;
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field__error">' . $h->e(implode(' ', $errors[$k])) . '</div>' : '';
$cls = static fn(string $k): string => isset($errors[$k]) ? 'field field--invalid' : 'field';
$num = static function (string $name, string $label, int $default, string $hint) use ($v, $err, $cls, $h): string {
    return '<div class="' . $cls($name) . '"><label for="f-' . $name . '">' . $h->t($label) . '</label><input type="number" id="f-' . $name . '" name="' . $name . '" value="' . $h->e($v($name, $default)) . '"><div class="field__hint">' . $h->t($hint) . '</div>' . $err($name) . '</div>';
};
$headersValue = $v('headers');
?>
<div class="page-head"><div class="page-head__titles"><h1><?= $isNew ? $h->t('New integration') : $h->t('Edit integration') ?></h1></div></div>
<?php if ($errors !== []): ?><div class="alert alert--error"><?= $h->t('Please correct the highlighted fields.') ?></div><?php endif ?>
<form method="post" action="<?= $isNew ? $h->url('admin.integrations.store') : $h->url('admin.integrations.update', ['id' => $row['id']]) ?>" class="card">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <?php if (!$isNew): ?><input type="hidden" name="version" value="<?= (int) $row['version'] ?>"><?php endif ?>
    <div class="card__head"><h2><?= $h->t('Connection') ?></h2></div>
    <div class="card__body form form--2">
        <div class="<?= $cls('code') ?>"><label for="f-code"><?= $h->t('Code') ?></label>
            <input type="text" id="f-code" name="code" value="<?= $h->e($v('code')) ?>" <?= $isNew ? 'required' : 'readonly' ?> pattern="[a-z0-9][a-z0-9_\-]{1,63}">
            <div class="field__hint"><?= $h->t('Stable identifier used by application code; cannot be changed later.') ?></div><?= $err('code') ?></div>
        <div class="<?= $cls('name') ?>"><label for="f-name"><?= $h->t('Name') ?></label><input type="text" id="f-name" name="name" value="<?= $h->e($v('name')) ?>" required maxlength="255"><?= $err('name') ?></div>
        <div class="<?= $cls('base_url') ?> span-2"><label for="f-url"><?= $h->t('Base URL') ?></label><input type="url" id="f-url" name="base_url" value="<?= $h->e($v('base_url')) ?>" placeholder="https://api.example.com"><div class="field__hint"><?= $h->t('Private, loopback and metadata addresses are rejected unless private network access is allowed (Security card).') ?></div><?= $err('base_url') ?></div>
        <div class="<?= $cls('health_path') ?>"><label for="f-hp"><?= $h->t('Health path') ?></label><input type="text" id="f-hp" name="health_path" value="<?= $h->e($v('health_path')) ?>" placeholder="/health"><div class="field__hint"><?= $h->t('Used by "Test connection" (GET).') ?></div><?= $err('health_path') ?></div>
        <div class="field"><label for="f-desc"><?= $h->t('Description') ?></label><input type="text" id="f-desc" name="description" value="<?= $h->e($v('description')) ?>"></div>
    </div>
    <div class="card__head"><h2><?= $h->t('Authentication') ?></h2></div>
    <div class="card__body form form--2">
        <div class="<?= $cls('auth_type') ?>"><label for="f-auth"><?= $h->t('Type') ?></label>
            <select id="f-auth" name="auth_type">
                <?php foreach (['none' => 'None', 'bearer' => 'Bearer token', 'basic' => 'HTTP Basic', 'api_key' => 'API key header', 'hmac' => 'HMAC-SHA256 request signature'] as $k => $l): ?>
                    <option value="<?= $k ?>"<?= $v('auth_type', 'none') === $k ? ' selected' : '' ?>><?= $h->t($l) ?></option>
                <?php endforeach ?>
            </select><div class="field__hint"><?= $h->t('The secret (token, password, key) is set separately and is write-only.') ?></div><?= $err('auth_type') ?></div>
        <div class="<?= $cls('auth_username') ?>"><label for="f-user"><?= $h->t('Username (Basic)') ?></label><input type="text" id="f-user" name="auth_username" value="<?= $h->e($v('auth_username')) ?>"><?= $err('auth_username') ?></div>
        <div class="<?= $cls('api_key_header') ?>"><label for="f-akh"><?= $h->t('API key header') ?></label><input type="text" id="f-akh" name="api_key_header" value="<?= $h->e($v('api_key_header')) ?>" placeholder="X-API-Key"><?= $err('api_key_header') ?></div>
        <div class="<?= $cls('hmac_header') ?>"><label for="f-hh"><?= $h->t('HMAC signature / timestamp headers') ?></label>
            <div class="row"><input type="text" id="f-hh" name="hmac_header" value="<?= $h->e($v('hmac_header')) ?>" placeholder="X-Signature" aria-label="<?= $h->t('Signature header') ?>"><input type="text" name="hmac_timestamp_header" value="<?= $h->e($v('hmac_timestamp_header')) ?>" placeholder="X-Timestamp" aria-label="<?= $h->t('Timestamp header') ?>"></div><?= $err('hmac_header') ?></div>
        <div class="<?= $cls('headers') ?> span-2"><label for="f-headers"><?= $h->t('Extra headers (JSON object)') ?></label><textarea class="code" id="f-headers" name="headers" rows="4" placeholder="{&quot;X-Tenant&quot;: &quot;acme&quot;}"><?= $h->e(is_array($headersValue) ? json_encode($headersValue) : $headersValue) ?></textarea><?= $err('headers') ?></div>
    </div>
    <div class="card__head"><h2><?= $h->t('Resilience') ?></h2></div>
    <div class="card__body form form--2">
        <?= $num('timeout_ms', 'Total timeout (ms)', 10000, '100–120000') ?>
        <?= $num('connect_timeout_ms', 'Connect timeout (ms)', 3000, '100–60000') ?>
        <?= $num('max_retries', 'Max retries', 3, 'Idempotent methods only (GET, PUT, DELETE…).') ?>
        <?= $num('backoff_ms', 'Backoff base (ms)', 200, 'Exponential backoff with jitter.') ?>
        <?= $num('circuit_threshold', 'Circuit threshold (failures)', 5, 'Consecutive failures that open the circuit.') ?>
        <?= $num('circuit_cooldown_s', 'Circuit cooldown (s)', 30, 'Open time before a half-open probe.') ?>
        <?= $num('rate_limit_per_minute', 'Rate limit (requests/min)', 0, '0 = unlimited.') ?>
    </div>
    <div class="card__foot">
        <a class="btn btn--ghost" data-back href="<?= $isNew ? $h->url('admin.integrations') : $h->url('admin.integrations.show', ['id' => $row['id']]) ?>"><?= $h->t('Cancel') ?></a>
        <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save') ?></button>
    </div>
</form>
