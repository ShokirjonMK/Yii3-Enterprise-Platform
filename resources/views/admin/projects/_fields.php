<?php

declare(strict_types=1);

/**
 * Project form fields (shared by Admin and Web forms). Errors per field from ValidationException.
 *
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array $values
 * @var array<string, list<string>> $errors
 * @var list<string> $priorities
 * @var bool $showOwner
 */

$showOwner ??= false;
$v = static fn(string $k): string => isset($values[$k]) && is_scalar($values[$k]) ? (string) $values[$k] : (is_array($values[$k] ?? null) ? implode(', ', $values[$k]) : '');
$field = static function (string $name, string $label, string $input, string $hint = '') use ($h, $errors): string {
    $err = $errors[$name] ?? [];
    $html = '<div class="field' . ($err !== [] ? ' field--invalid' : '') . '"><label for="f-' . $h->e($name) . '">' . $h->t($label) . '</label>' . $input;
    if ($hint !== '') {
        $html .= '<div class="field__hint">' . $h->t($hint) . '</div>';
    }
    foreach ($err as $m) {
        $html .= '<div class="field__error" id="e-' . $h->e($name) . '">' . $h->e($m) . '</div>';
    }
    return $html . '</div>';
};
$attrs = static fn(string $name): string => ' id="f-' . $h->e($name) . '" name="' . $h->e($name) . '"' . (isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="e-' . $h->e($name) . '"' : '');
?>
<?php if ($errors !== []): ?>
    <div class="alert alert--error span-2" role="alert"><?= $h->t('Please correct the highlighted fields.') ?></div>
<?php endif ?>
<?= $field('code', 'Code', '<input type="text" required maxlength="32"' . $attrs('code') . ' value="' . $h->e($v('code')) . '" autocomplete="off">', 'Unique within the tenant, e.g. CRM-2026.') ?>
<?= $field('name', 'Name', '<input type="text" required maxlength="200"' . $attrs('name') . ' value="' . $h->e($v('name')) . '">') ?>
<div class="span-2"><?= $field('description', 'Description', '<textarea rows="4" maxlength="5000"' . $attrs('description') . '>' . $h->e($v('description')) . '</textarea>') ?></div>
<?php
$opts = '';
foreach ($priorities as $p) {
    $opts .= '<option value="' . $h->e($p) . '"' . ($v('priority') === $p ? ' selected' : '') . '>' . $h->t(ucfirst($p)) . '</option>';
}
echo $field('priority', 'Priority', '<select' . $attrs('priority') . '>' . $opts . '</select>');
echo $field('budget', 'Budget', '<input type="text" inputmode="decimal"' . $attrs('budget') . ' value="' . $h->e($v('budget')) . '" placeholder="0.00">');
echo $field('starts_on', 'Start date', '<input type="date"' . $attrs('starts_on') . ' value="' . $h->e($v('starts_on')) . '">');
echo $field('due_on', 'Due date', '<input type="date"' . $attrs('due_on') . ' value="' . $h->e($v('due_on')) . '">', 'Must not be before the start date.');
echo $field('tags', 'Tags', '<input type="text"' . $attrs('tags') . ' value="' . $h->e($v('tags')) . '">', 'Comma-separated, up to 10 (lowercase letters, digits, - and _).');
if ($showOwner) {
    echo $field('owner_id', 'Owner (user id)', '<input type="text"' . $attrs('owner_id') . ' value="' . $h->e($v('owner_id')) . '" placeholder="' . $h->t('Leave empty: yourself') . '">', 'Assigning another owner requires projects.manage_all.');
}
?>
