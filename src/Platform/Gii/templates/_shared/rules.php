<?php

declare(strict_types=1);

/**
 * Template: validation rules (src/Modules/<Module>/Domain/<Entity>Rules.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$list = static fn(array $items): string => '[' . implode(', ', array_map($q, $items)) . ']';
$blocks = [];
foreach ($m->editable() as $f) {
    $blocks[] = FieldCode::normalize($f, $m->entity);
}
$labels = [];
foreach ($m->fields as $f) {
    $labels[] = '        ' . $q($f->name) . ' => ' . $q($f->label()) . ',';
}
$options = [];
foreach ($m->fields as $f) {
    if ($f->type === 'enum') {
        $options[] = '    public const ' . $f->constName() . '_OPTIONS = ' . $list($f->options) . ';';
    }
}
$uses = ['use App\Shared\Domain\Exception\ValidationException;'];
if ($m->ofType('user', 'uuid') !== []) {
    $uses[] = 'use App\Shared\Domain\Uuid;';
}
if ($m->ofType('date', 'datetime') !== []) {
    $uses[] = 'use DateTimeImmutable;';
}

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Domain;

__USES__

/**
 * Input normalisation + validation of {{entityLabelLower}} attributes (pure, no I/O).
 * Unknown keys are ignored; on partial updates only the keys present in the input are validated.
 * Error messages are keyed by field (ValidationException → 422 with details per field).
 */
final class {{Entity}}Rules
{
    public const FIELDS = __FIELDS__;
    public const EDITABLE = __EDITABLE__;
    public const REQUIRED = __REQUIRED__;
    public const LABELS = [
__LABELS__
    ];__OPTIONS__

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed> normalised values (column names)
     * @throws ValidationException
     */
    public static function normalize(array $in, bool $creating): array
    {
        $errors = [];
        $out = [];
        $has = static fn(string $k): bool => array_key_exists($k, $in);
        $str = static fn(string $k): string => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';

__BLOCKS__

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $out;
    }
}

PHP, [
    '__USES__' => implode("\n", $uses),
    '__FIELDS__' => $list(array_map(static fn($f) => $f->name, $m->fields)),
    '__EDITABLE__' => $list(array_map(static fn($f) => $f->name, $m->editable())),
    '__REQUIRED__' => $list(array_map(static fn($f) => $f->name, array_filter($m->editable(), static fn($f) => $f->required))),
    '__LABELS__' => implode("\n", $labels),
    '__OPTIONS__' => $options === [] ? '' : "\n" . implode("\n", $options),
    '__BLOCKS__' => implode("\n\n", $blocks),
]);
