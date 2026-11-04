<?php

declare(strict_types=1);

/**
 * @var Yiisoft\View\WebView $this
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var list<string> $groups
 * @var string $group
 * @var array<string, array<string,mixed>> $items
 * @var string $scope
 * @var string|null $tenant
 * @var list<array{id:string, code:string, name:string}> $tenants
 * @var array<string, list<string>> $errors
 * @var array<string, mixed> $submitted
 * @var bool $canManage
 * @var bool $isSuperadmin
 * @var string $csrf
 */
$this->setTitle($h->tr('System Settings'));
$this->setParameter('breadcrumbs', [$h->tr('System Settings')]);
$scopeQuery = array_filter(['scope' => $scope === 'tenant' ? 'tenant' : null, 'tenant' => $tenant]);
$sourceTone = ['env' => 'info', 'tenant' => 'warn', 'user' => 'warn', 'global' => 'ok', 'default' => 'muted'];

$display = static function (array $it): string {
    $v = $it['value'];
    return match ($it['type']) {
        'bool' => $v ? 'true' : 'false',
        'json' => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'secret' => $v === null ? '' : '********',
        default => (string) $v,
    };
};
$input = static function (string $key, array $it, mixed $override, bool $editable) use ($h, $display): string {
    $id = 'set-' . preg_replace('/[^a-z0-9]/', '-', $key);
    $name = 'settings[' . $key . ']';
    $ro = $editable ? '' : ' disabled';
    $value = $override ?? ($it['type'] === 'json' ? (string) json_encode($it['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $display($it));
    return match ($it['type']) {
        'bool' => '<label class="check"><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . (($override !== null ? $override === '1' : (bool) $it['value']) ? ' checked' : '') . $ro . '> ' . $h->t('Enabled') . '</label>',
        'int' => '<input type="number" id="' . $id . '" name="' . $name . '" value="' . $h->e($value) . '"' . (isset($it['min']) ? ' min="' . (int) $it['min'] . '"' : '') . (isset($it['max']) ? ' max="' . (int) $it['max'] . '"' : '') . $ro . '>',
        'enum' => '<select id="' . $id . '" name="' . $name . '"' . $ro . '>' . implode('', array_map(static fn($o) => '<option value="' . $h->e($o) . '"' . ((string) $value === (string) $o ? ' selected' : '') . '>' . $h->e($o) . '</option>', $it['options'] ?? [])) . '</select>',
        'json', 'text' => '<textarea class="' . ($it['type'] === 'json' ? 'code' : '') . '" id="' . $id . '" name="' . $name . '" rows="3"' . $ro . '>' . $h->e($value) . '</textarea>',
        default => '<input type="text" id="' . $id . '" name="' . $name . '" value="' . $h->e($value) . '"' . (isset($it['max']) ? ' maxlength="' . (int) $it['max'] . '"' : '') . $ro . '>',
    };
};
$protected = array_filter($items, static fn($it) => ($it['critical'] ?? false) || $it['type'] === 'secret');
$regular = array_diff_key($items, $protected);
$editableIn = static fn(array $it): bool => in_array($scope, $it['scopes'] ?? ['global'], true) && $it['source'] !== 'env';
?>
<?= $this->render('./_tabs', ['active' => 'settings']) ?>
<div class="card mb">
    <form class="toolbar" method="get" action="<?= $h->url('admin.settings') ?>">
        <input type="hidden" name="group" value="<?= $h->e($group) ?>">
        <select name="scope" aria-label="<?= $h->t('Scope') ?>">
            <option value="global"<?= $scope === 'global' ? ' selected' : '' ?>><?= $h->t('Global') ?></option>
            <option value="tenant"<?= $scope === 'tenant' ? ' selected' : '' ?>><?= $h->t('Tenant override') ?></option>
        </select>
        <select name="tenant" aria-label="<?= $h->t('Tenant') ?>"><option value=""><?= $h->t('— tenant —') ?></option>
            <?php foreach ($tenants as $t): ?><option value="<?= $h->e($t['id']) ?>"<?= $tenant === $t['id'] ? ' selected' : '' ?>><?= $h->e($t['code'] . ' · ' . $t['name']) ?></option><?php endforeach ?></select>
        <button class="btn" type="submit"><?= $h->t('Apply scope') ?></button>
        <span class="small muted"><?= $scope === 'tenant' ? $h->t('Editing tenant overrides; only tenant-scoped keys are editable.') : $h->t('Editing global values.') ?></span>
    </form>
</div>
<nav class="tabs" hx-boost="true" hx-target="#main" hx-select="#main" hx-swap="outerHTML">
    <?php foreach ($groups as $g): ?><a href="<?= $h->url('admin.settings', [], ['group' => $g] + $scopeQuery) ?>"<?= $g === $group ? ' aria-current="page"' : '' ?>><?= $h->t($g) ?></a><?php endforeach ?>
</nav>
<?php if ($errors !== []): ?><div class="alert alert--error"><?= $h->t('Some values were not saved — see the highlighted fields.') ?></div><?php endif ?>

<div class="grid grid--sidebar">
<form class="card" method="post" action="<?= $h->url('admin.settings.save') ?>">
    <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>">
    <input type="hidden" name="group" value="<?= $h->e($group) ?>">
    <input type="hidden" name="scope" value="<?= $h->e($scope) ?>"><input type="hidden" name="tenant" value="<?= $h->e($tenant ?? '') ?>">
    <div class="card__head"><h2><?= $h->t($group) ?></h2></div>
    <div class="card__body form">
        <?php if ($regular === []): ?><p class="muted"><?= $h->t('All settings of this group are protected (see the right column).') ?></p><?php endif ?>
        <?php foreach ($regular as $key => $it): $editable = $canManage && $editableIn($it); ?>
            <div class="field<?= isset($errors[$key]) ? ' field--invalid' : '' ?>" data-setting="<?= $h->e($key) ?>">
                <label for="set-<?= $h->e(preg_replace('/[^a-z0-9]/', '-', $key)) ?>"><?= $h->t($it['label'] ?? $key) ?></label>
                <?php if ($editable): ?><input type="hidden" name="present[<?= $h->e($key) ?>]" value="1"><?php endif ?>
                <?= $input($key, $it, isset($submitted[$key]) && is_scalar($submitted[$key]) ? (string) $submitted[$key] : null, $editable) ?>
                <div class="field__hint row">
                    <span class="mono"><?= $h->e($key) ?></span>
                    <span class="badge badge--<?= $sourceTone[$it['source']] ?? 'muted' ?>" data-source="<?= $h->e($it['source']) ?>"><?= $h->t('source: {s}', ['s' => $it['source']]) ?></span>
                    <?php if ($it['source'] === 'env'): ?><span><?= $h->t('Set by environment variable {env} — read-only here.', ['env' => $it['env'] ?? '']) ?></span><?php endif ?>
                    <a href="<?= $h->url('admin.settings.history', ['key' => $key], $scopeQuery) ?>"><?= $h->t('history') ?></a>
                </div>
                <?php if (isset($errors[$key])): ?><div class="field__error"><?= $h->e(implode(' ', $errors[$key])) ?></div><?php endif ?>
            </div>
        <?php endforeach ?>
        <?php if ($canManage && $regular !== []): ?>
            <div class="field"><label for="set-comment"><?= $h->t('Change comment (stored in history)') ?></label><input type="text" id="set-comment" name="comment" maxlength="500"></div>
        <?php endif ?>
    </div>
    <?php if ($canManage && $regular !== []): ?><div class="card__foot"><button class="btn btn--primary" type="submit"><?= $h->icon('check') ?> <?= $h->t('Save changes') ?></button></div><?php endif ?>
</form>

<div class="stack">
    <?php foreach ($protected as $key => $it): $editable = $canManage && $editableIn($it) && (!($it['critical'] ?? false) || $isSuperadmin); ?>
    <form class="card" method="post" action="<?= $h->url('admin.settings.secure') ?>" data-setting="<?= $h->e($key) ?>">
        <input type="hidden" name="_csrf" value="<?= $h->e($csrf) ?>"><input type="hidden" name="key" value="<?= $h->e($key) ?>">
        <input type="hidden" name="scope" value="<?= $h->e($scope) ?>"><input type="hidden" name="tenant" value="<?= $h->e($tenant ?? '') ?>">
        <div class="card__head"><h3><?= $h->icon('lock') ?> <?= $h->t($it['label'] ?? $key) ?></h3>
            <?= ($it['critical'] ?? false) ? $h->badge('critical', $h->tr('critical')) : '' ?><?= $it['type'] === 'secret' ? $h->badge('info', $h->tr('secret')) : '' ?></div>
        <div class="card__body form">
            <?php if ($it['type'] === 'secret'): ?>
                <input type="password" name="value" autocomplete="new-password" aria-label="<?= $h->t('New value') ?>" placeholder="<?= $it['is_set'] ? $h->t('•••••••• (set — leave empty to keep)') : $h->t('not set') ?>"<?= $editable ? '' : ' disabled' ?>>
                <?php if ($it['is_set'] && $editable && $it['source'] !== 'env'): ?><label class="check"><input type="checkbox" name="clear" value="1"> <?= $h->t('Clear the stored secret') ?></label><?php endif ?>
            <?php else: ?>
                <?= str_replace('name="settings[' . $key . ']"', 'name="value"', $input($key, $it, null, $editable)) ?>
            <?php endif ?>
            <div class="field__hint row"><span class="mono"><?= $h->e($key) ?></span>
                <span class="badge badge--<?= $sourceTone[$it['source']] ?? 'muted' ?>" data-source="<?= $h->e($it['source']) ?>"><?= $h->t('source: {s}', ['s' => $it['source']]) ?></span>
                <a href="<?= $h->url('admin.settings.history', ['key' => $key], $scopeQuery) ?>"><?= $h->t('history') ?></a></div>
            <?php if ($it['source'] === 'env'): ?><div class="field__hint"><?= $h->t('Set by environment variable {env} — read-only here.', ['env' => $it['env'] ?? '']) ?></div><?php endif ?>
            <?php if (($it['critical'] ?? false) && !$isSuperadmin): ?><div class="field__hint"><?= $h->t('Only a superadmin can change critical settings.') ?></div><?php endif ?>
            <?php if (isset($errors[$key])): ?><div class="field__error"><?= $h->e(implode(' ', array_unique($errors[$key]))) ?></div><?php endif ?>
        </div>
        <?php if ($editable): ?><div class="card__foot"><span class="small muted"><?= $h->t('Requires re-authentication') ?></span><button class="btn btn--sm btn--primary" type="submit"><?= $h->icon('shield') ?> <?= $h->t('Save') ?></button></div><?php endif ?>
    </form>
    <?php endforeach ?>
</div>
</div>
