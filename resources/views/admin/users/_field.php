<?php

declare(strict_types=1);

/**
 * Generic form field (input/select/textarea/checkbox) with label, hint and errors.
 * Shared by the identity admin sections (02–06): render with '//admin/users/_field'.
 *
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var string $name        input name (may contain [] / brackets)
 * @var string $label       translated label (raw — it is encoded here)
 * @var mixed $value
 * @var array $errors       field => list<string>
 * @var string|null $type   text|email|password|number|url|date|datetime-local|select|textarea|checkbox
 * @var array|null $options value => label (select)
 * @var string|null $hint
 * @var bool|null $required
 * @var string|null $errorKey  key in $errors (defaults to $name)
 * @var string|null $class     extra wrapper class (e.g. span-2)
 * @var array|null $attrs      extra attributes (already safe names; values are encoded)
 */

$type ??= 'text';
$options ??= [];
$hint ??= null;
$required ??= false;
$errorKey ??= $name;
$class ??= '';
$attrs ??= [];
$id = 'f-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
$fieldErrors = $errors[$errorKey] ?? [];
$extra = '';
foreach ($attrs as $k => $v) {
    $extra .= ' ' . preg_replace('/[^a-z0-9:-]/i', '', (string) $k) . '="' . $h->e($v) . '"';
}
$describedBy = trim(($hint ? $id . '-hint ' : '') . ($fieldErrors ? $id . '-err' : ''));
$aria = ($describedBy !== '' ? ' aria-describedby="' . $h->e($describedBy) . '"' : '') . ($fieldErrors ? ' aria-invalid="true"' : '') . ($required ? ' required' : '');
?>
<div class="field<?= $fieldErrors ? ' field--invalid' : '' ?><?= $class !== '' ? ' ' . $h->e($class) : '' ?>">
    <?php if ($type === 'checkbox'): ?>
        <label class="check" for="<?= $h->e($id) ?>">
            <input type="hidden" name="<?= $h->e($name) ?>" value="0">
            <input type="checkbox" id="<?= $h->e($id) ?>" name="<?= $h->e($name) ?>" value="1"<?= !empty($value) && $value !== '0' && $value !== 'f' ? ' checked' : '' ?><?= $aria . $extra ?>>
            <?= $h->e($label) ?>
        </label>
    <?php else: ?>
        <label for="<?= $h->e($id) ?>"><?= $h->e($label) ?><?= $required ? ' <span aria-hidden="true">*</span>' : '' ?></label>
        <?php if ($type === 'select'): ?>
            <select id="<?= $h->e($id) ?>" name="<?= $h->e($name) ?>"<?= $aria . $extra ?>>
                <?php foreach ($options as $ov => $ol): ?>
                    <option value="<?= $h->e($ov) ?>"<?= (string) $ov === (string) $value ? ' selected' : '' ?>><?= $h->e($ol) ?></option>
                <?php endforeach ?>
            </select>
        <?php elseif ($type === 'textarea'): ?>
            <textarea id="<?= $h->e($id) ?>" name="<?= $h->e($name) ?>" rows="4"<?= $aria . $extra ?>><?= $h->e(is_array($value) ? implode("\n", $value) : $value) ?></textarea>
        <?php else: ?>
            <input type="<?= $h->e($type) ?>" id="<?= $h->e($id) ?>" name="<?= $h->e($name) ?>" value="<?= $type === 'password' ? '' : $h->e(is_array($value) ? implode(' ', $value) : $value) ?>"<?= $aria . $extra ?>>
        <?php endif ?>
    <?php endif ?>
    <?php if ($hint): ?><div class="field__hint" id="<?= $h->e($id) ?>-hint"><?= $h->e($hint) ?></div><?php endif ?>
    <?php foreach ($fieldErrors as $err): ?><div class="field__error" id="<?= $h->e($id) ?>-err"><?= $h->t($err) ?></div><?php endforeach ?>
</div>
