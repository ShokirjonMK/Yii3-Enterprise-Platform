<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string|null $name null = new connection
 * @var array<string, mixed> $values
 * @var array<string, list<string>> $errors
 * @var array<string, array{label:string, port:int, unavailable:?string}> $drivers
 * @var bool $hasPassword
 * @var string $csrf
 */
$isNew = $name === null;
$this->setTitle($isNew ? $h->tr('New database connection') : $h->tr('Edit {name}', ['name' => $name]));
$this->setParameter('breadcrumbs', [$h->tr('Databases') => $h->url('admin.databases')] + ($isNew ? [] : [$name => $h->url('admin.databases.show', ['name' => $name])]) + [$isNew ? $h->tr('New') : $h->tr('Edit')]);
$v = static fn(string $k, mixed $d = '') => $values[$k] ?? $d;
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field__error">' . $h->e(implode(' ', $errors[$k])) . '</div>' : '';
$cls = static fn(string $k, string $extra = ''): string => trim((isset($errors[$k]) ? 'field field--invalid' : 'field') . ' ' . $extra);
$checked = static fn(string $k): string => filter_var($v($k, false), FILTER_VALIDATE_BOOLEAN) ? ' checked' : '';
?>
<div class="page-head"><div class="page-head__titles">
    <h1><?= $isNew ? $h->t('New database connection') : $h->t('Edit connection') ?></h1>
    <p class="page-head__sub"><?= $h->t('Any engine: PostgreSQL, MySQL/MariaDB, SQLite, SQL Server, Oracle, MongoDB, ClickHouse. Test before saving.') ?></p>
</div></div>
<?php if ($errors !== []): ?><div class="alert alert--error"><?= $h->t('Please correct the highlighted fields.') ?></div><?php endif ?>

<form method="post" action="<?= $isNew ? $h->url('admin.databases.store') : $h->url('admin.databases.update', ['name' => $name]) ?>" class="card" id="db-form">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <?php if (!$isNew): ?><input type="hidden" name="version" value="<?= (int) $v('version', 0) ?>"><input type="hidden" name="existing" value="<?= $h->e($name) ?>"><?php endif ?>
    <div class="card__head"><h2><?= $h->t('Connection') ?></h2></div>
    <div class="card__body form form--2">
        <div class="<?= $cls('name') ?>"><label for="f-name"><?= $h->t('Name') ?></label>
            <input type="text" id="f-name" name="name" value="<?= $h->e($isNew ? $v('name') : $name) ?>" <?= $isNew ? 'required' : 'readonly' ?> pattern="[a-z][a-z0-9_]{1,63}" placeholder="analytics">
            <div class="field__hint"><?= $h->t('Used in code: $dbs->sql(\'name\'). Lower-case letters, digits, "_"; cannot be changed later.') ?></div><?= $err('name') ?></div>
        <div class="<?= $cls('driver') ?>"><label for="f-driver"><?= $h->t('Database type') ?></label>
            <select id="f-driver" name="driver"<?= $isNew ? '' : ' disabled' ?>>
                <?php foreach ($drivers as $k => $dr): ?>
                    <option value="<?= $h->e($k) ?>"<?= $v('driver') === $k ? ' selected' : '' ?>><?= $h->e($dr['label']) ?><?= $dr['port'] > 0 ? ' · ' . $dr['port'] : '' ?><?= $dr['unavailable'] !== null ? ' — ' . $h->t('not available') : '' ?></option>
                <?php endforeach ?>
            </select>
            <?php if (!$isNew): ?><input type="hidden" name="driver" value="<?= $h->e($v('driver')) ?>"><?php endif ?>
            <div class="field__hint"><?= $h->t('Default port in brackets; leave the port empty to use it.') ?></div><?= $err('driver') ?></div>
        <div class="<?= $cls('host') ?>"><label for="f-host"><?= $h->t('Host') ?></label><input type="text" id="f-host" name="host" value="<?= $h->e($v('host')) ?>" placeholder="db.internal"><div class="field__hint"><?= $h->t('Not used for SQLite.') ?></div><?= $err('host') ?></div>
        <div class="<?= $cls('port') ?>"><label for="f-port"><?= $h->t('Port') ?></label><input type="number" id="f-port" name="port" min="0" max="65535" value="<?= $h->e($v('port')) ?>"><?= $err('port') ?></div>
        <div class="<?= $cls('database') ?>"><label for="f-db"><?= $h->t('Database') ?></label><input type="text" id="f-db" name="database" value="<?= $h->e($v('database')) ?>">
            <div class="field__hint"><?= $h->t('Database name · Oracle: service name · SQLite: file name (stored in runtime/databases) · ClickHouse: empty = default.') ?></div><?= $err('database') ?></div>
        <div class="<?= $cls('username') ?>"><label for="f-user"><?= $h->t('User') ?></label><input type="text" id="f-user" name="username" value="<?= $h->e($v('username')) ?>" autocomplete="off"><?= $err('username') ?></div>
        <div class="<?= $cls('password') ?>"><label for="f-pass"><?= $h->t('Password') ?></label><input type="password" id="f-pass" name="password" value="" autocomplete="new-password"<?= $hasPassword ? ' placeholder="' . $h->t('•••••• (unchanged)') . '"' : '' ?>>
            <div class="field__hint"><?= $hasPassword ? $h->t('Leave empty to keep the stored password.') : $h->t('Stored encrypted; never shown again.') ?></div>
            <?php if ($hasPassword): ?><label class="check small"><input type="checkbox" name="clear_password" value="1"> <?= $h->t('Remove the stored password') ?></label><?php endif ?><?= $err('password') ?></div>
        <div class="<?= $cls('options', 'span-2') ?>"><label for="f-opts"><?= $h->t('Options (JSON object)') ?></label>
            <textarea class="code" id="f-opts" name="options" rows="3" placeholder='{"sslmode": "require", "timeout": 5}'><?= $h->e($v('options')) ?></textarea>
            <div class="field__hint"><?= $h->t('Driver options: sslmode, timeout (s), charset, tls, authSource, replicaSet, srv, readPreference, service, encrypt, trustServerCertificate.') ?></div><?= $err('options') ?></div>
        <div class="<?= $cls('description', 'span-2') ?>"><label for="f-desc"><?= $h->t('Description') ?></label><input type="text" id="f-desc" name="description" maxlength="500" value="<?= $h->e($v('description')) ?>"><?= $err('description') ?></div>
        <div class="field"><label class="check"><input type="checkbox" name="read_only" value="1"<?= $checked('read_only') ?>> <?= $h->t('Read-only connection') ?></label>
            <div class="field__hint"><?= $h->t('Enforced by the server where possible (PostgreSQL, MySQL, SQLite, ClickHouse); the console never offers write mode.') ?></div></div>
        <div class="field"><input type="hidden" name="enabled" value="0"><label class="check"><input type="checkbox" name="enabled" value="1"<?= $checked('enabled') ?>> <?= $h->t('Enabled') ?></label></div>
    </div>
    <div class="card__body"><div id="test-result"></div></div>
    <div class="card__foot">
        <a class="btn btn--ghost" data-back href="<?= $isNew ? $h->url('admin.databases') : $h->url('admin.databases.show', ['name' => $name]) ?>"><?= $h->t('Cancel') ?></a>
        <button class="btn" type="button" hx-post="<?= $h->url('admin.databases.test_draft') ?>" hx-include="#db-form" hx-target="#test-result" hx-swap="outerHTML"><?= $h->icon('play') ?> <?= $h->t('Test connection') ?></button>
        <button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save') ?></button>
    </div>
</form>
