<?php

declare(strict_types=1);

/**
 * Template: form fields partial (resources/views/{admin,web}/<module>/_fields.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$rules = '\\App\\Modules\\' . $m->module . '\\Domain\\' . $m->entity . 'Rules';
$inputs = [];
foreach ($m->editable() as $f) {
    $inputs[] = FieldCode::formInput($f, $rules);
}
$hasEnum = array_filter($m->editable(), static fn($f) => $f->type === 'enum') !== [];

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

/**
 * {{entityLabel}} form fields. Errors per field come from ValidationException (422).
 *
 * @var App\Shared\Infrastructure\View\ViewHelpers $h
 * @var array<string,mixed> $values
 * @var array<string, list<string>> $errors
 */

$v = static fn(string $k): string => isset($values[$k]) && is_scalar($values[$k]) ? (string) (is_bool($values[$k]) ? (int) $values[$k] : $values[$k]) : '';
$field = static function (string $name, string $label, string $input) use ($h, $errors): string {
    $err = $errors[$name] ?? [];
    $html = '<div class="field' . ($err !== [] ? ' field--invalid' : '') . '"><label for="f-' . $h->e($name) . '">' . $h->t($label) . '</label>' . $input;
    foreach ($err as $msg) {
        $html .= '<div class="field__error" id="e-' . $h->e($name) . '">' . $h->e($msg) . '</div>';
    }
    return $html . '</div>';
};
$attrs = static fn(string $name): string => ' id="f-' . $h->e($name) . '" name="' . $h->e($name) . '"' . (isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="e-' . $h->e($name) . '"' : '');__OPTIONS__
?>
<?php if ($errors !== []): ?>
    <div class="alert alert--error span-2" role="alert"><?= $h->t('Please correct the highlighted fields.') ?></div>
<?php endif ?>
<?php
__INPUTS__
?>

PHP, [
    '__OPTIONS__' => $hasEnum ? "\n\$options = static function (array \$list, string \$current) use (\$h): string {\n    \$html = '';\n    foreach (\$list as \$o) {\n        \$html .= '<option value=\"' . \$h->e(\$o) . '\"' . (\$current === \$o ? ' selected' : '') . '>' . \$h->t(ucfirst(str_replace('_', ' ', (string) \$o))) . '</option>';\n    }\n    return \$html;\n};" : '',
    '__INPUTS__' => implode("\n", $inputs),
]);
