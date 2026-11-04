<?php

declare(strict_types=1);

namespace App\Platform\Gii\Spec;

/**
 * Per-field code fragments used by the templates (validation, hydration, form inputs, list cells,
 * OpenAPI properties, test fixtures). Keeping them here keeps the templates readable and makes
 * every field type behave the same in Domain, UI, API and tests.
 */
final class FieldCode
{
    public static function q(string $s): string
    {
        return var_export($s, true);
    }

    /** Validation + normalisation block inside `<Entity>Rules::normalize()` (8-space indent). */
    public static function normalize(FieldSpec $f, string $entity): string
    {
        $n = $f->name;
        $k = self::q($n);
        $L = $f->label();
        $req = $f->required;
        $lines = [];
        $chain = [];
        $assign = '';
        switch ($f->type) {
            case 'string':
            case 'text':
            case 'email':
                $lines[] = match ($f->type) {
                    'string' => "\$v = preg_replace('/\\s+/u', ' ', \$str($k)) ?? '';",
                    'email' => "\$v = mb_strtolower(\$str($k));",
                    default => "\$v = \$str($k);",
                };
                if ($req) {
                    $chain[] = ["\$v === ''", "$L is required."];
                }
                if ($f->type === 'email') {
                    $chain[] = ["\$v !== '' && filter_var(\$v, FILTER_VALIDATE_EMAIL) === false", "$L must be a valid e-mail address."];
                }
                $chain[] = ["mb_strlen(\$v) > " . $f->maxLength(), sprintf('%s must not exceed %d characters.', $L, $f->maxLength())];
                if ($f->min !== null) {
                    $chain[] = ["\$v !== '' && mb_strlen(\$v) < " . (int) $f->min, sprintf('%s must be at least %d characters.', $L, (int) $f->min)];
                }
                $assign = "\$out[$k] = \$v === '' ? null : \$v;";
                break;
            case 'int':
                $lines[] = "\$raw = \$str($k);";
                $lines[] = "\$v = \$raw === '' ? null : filter_var(\$raw, FILTER_VALIDATE_INT);";
                if ($req) {
                    $chain[] = ["\$v === null", "$L is required."];
                }
                $chain[] = ["\$v === false", "$L must be a whole number."];
                if ($f->min !== null) {
                    $chain[] = ["is_int(\$v) && \$v < " . FieldSpec::num($f->min), "$L must be at least " . FieldSpec::num($f->min) . '.'];
                }
                if ($f->max !== null) {
                    $chain[] = ["is_int(\$v) && \$v > " . FieldSpec::num($f->max), "$L must not exceed " . FieldSpec::num($f->max) . '.'];
                }
                $assign = "\$out[$k] = is_int(\$v) ? \$v : null;";
                break;
            case 'decimal':
                $lines[] = "\$raw = \$in[$k] ?? null;";
                $lines[] = "\$v = is_int(\$raw) || is_float(\$raw) ? number_format((float) \$raw, 2, '.', '') : str_replace([' ', ','], ['', '.'], \$str($k));";
                $lines[] = "\$ok = preg_match('/^-?\\d{1,12}(\\.\\d{1,2})?\$/', \$v) === 1;";
                if ($req) {
                    $chain[] = ["\$v === ''", "$L is required."];
                }
                $chain[] = ["\$v !== '' && !\$ok", "$L must be a number with at most 2 decimals."];
                if ($f->min !== null) {
                    $chain[] = ["\$ok && (float) \$v < " . FieldSpec::num($f->min), "$L must be at least " . FieldSpec::num($f->min) . '.'];
                }
                if ($f->max !== null) {
                    $chain[] = ["\$ok && (float) \$v > " . FieldSpec::num($f->max), "$L must not exceed " . FieldSpec::num($f->max) . '.'];
                }
                $assign = "\$out[$k] = \$ok ? number_format((float) \$v, 2, '.', '') : null;";
                break;
            case 'bool':
                $default = $f->default === 'true' ? 'true' : 'false';
                $lines[] = "\$raw = \$in[$k] ?? null;";
                $assign = "\$out[$k] = !\$has($k) ? $default : (is_bool(\$raw) ? \$raw : in_array(mb_strtolower(\$str($k)), ['1', 'true', 'on', 'yes'], true));";
                break;
            case 'date':
                $lines[] = "\$v = \$str($k);";
                $lines[] = "\$d = \$v === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', \$v);";
                $lines[] = "\$ok = \$d instanceof DateTimeImmutable && \$d->format('Y-m-d') === \$v;";
                if ($req) {
                    $chain[] = ["\$v === ''", "$L is required."];
                }
                $chain[] = ["\$v !== '' && !\$ok", "$L: use the date format YYYY-MM-DD."];
                $assign = "\$out[$k] = \$ok ? \$v : null;";
                break;
            case 'datetime':
                $lines[] = "\$v = \$str($k);";
                $lines[] = "\$d = null;";
                $lines[] = "if (\$v !== '') {";
                $lines[] = "    try {";
                $lines[] = "        \$d = new DateTimeImmutable(\$v);";
                $lines[] = "    } catch (\\Exception) {";
                $lines[] = "        \$d = false;";
                $lines[] = "    }";
                $lines[] = "}";
                if ($req) {
                    $chain[] = ["\$v === ''", "$L is required."];
                }
                $chain[] = ["\$d === false", "$L must be a valid date and time."];
                $assign = "\$out[$k] = \$d instanceof DateTimeImmutable ? \$d->format(DATE_ATOM) : null;";
                break;
            case 'enum':
                $const = 'self::' . $f->constName() . '_OPTIONS';
                $lines[] = "\$v = \$str($k);";
                $lines[] = "\$v = \$v === '' ? " . self::q($f->defaultOption()) . " : \$v;";
                $chain[] = ["!in_array(\$v, $const, true)", "$L must be one of: " . implode(', ', $f->options) . '.'];
                $assign = "\$out[$k] = \$v;";
                break;
            case 'user':
            case 'uuid':
                $lines[] = "\$v = mb_strtolower(\$str($k));";
                if ($req) {
                    $chain[] = ["\$v === ''", "$L is required."];
                }
                $chain[] = ["\$v !== '' && !Uuid::isValid(\$v)", $f->type === 'user' ? "$L must be a valid user id." : "$L must be a valid UUID."];
                $assign = "\$out[$k] = \$v === '' ? null : \$v;";
                break;
        }

        $out = ["if (\$creating || \$has($k)) {"];
        foreach ($lines as $l) {
            $out[] = '    ' . $l;
        }
        foreach ($chain as $i => [$cond, $msg]) {
            $out[] = '    ' . ($i === 0 ? 'if' : '} elseif') . " ($cond) {";
            $out[] = "        \$errors[$k][] = " . self::q($msg) . ';';
        }
        if ($chain !== []) {
            $out[] = '    }';
        }
        $out[] = '    ' . $assign;
        $out[] = '}';
        return implode("\n", array_map(static fn($l) => '        ' . $l, $out));
    }

    /** Expression converting a DB row value into the normalised domain value. */
    public static function fromRow(FieldSpec $f, string $var = '$r'): string
    {
        $v = $var . '[' . self::q($f->name) . ']';
        $nullGuard = static fn(string $expr) => $f->nullable() ? "$v === null ? null : $expr" : $expr;
        return match ($f->type) {
            'int' => $nullGuard("(int) $v"),
            'decimal' => $nullGuard("number_format((float) $v, 2, '.', '')"),
            'bool' => "in_array($v, [true, 1, '1', 't', 'true'], true)",
            'date' => $nullGuard("substr((string) $v, 0, 10)"),
            'datetime' => $nullGuard("(new DateTimeImmutable((string) $v))->format(DATE_ATOM)"),
            default => $nullGuard("(string) $v"),
        };
    }

    /** Form control for `_fields.php` (uses $field(), $attrs(), $v() helpers of the view). */
    public static function formInput(FieldSpec $f, string $rulesClass): string
    {
        $n = self::q($f->name);
        $L = self::q($f->label());
        $req = $f->required ? ' required' : '';
        return match ($f->type) {
            'string' => "echo \$field($n, $L, '<input type=\"text\"$req maxlength=\"{$f->maxLength()}\"' . \$attrs($n) . ' value=\"' . \$h->e(\$v($n)) . '\">');",
            'email' => "echo \$field($n, $L, '<input type=\"email\"$req maxlength=\"254\"' . \$attrs($n) . ' value=\"' . \$h->e(\$v($n)) . '\">');",
            'text' => "echo '<div class=\"span-2\">' . \$field($n, $L, '<textarea rows=\"4\"$req maxlength=\"{$f->maxLength()}\"' . \$attrs($n) . '>' . \$h->e(\$v($n)) . '</textarea>') . '</div>';",
            'int' => "echo \$field($n, $L, '<input type=\"number\" step=\"1\"$req" . ($f->min !== null ? ' min="' . FieldSpec::num($f->min) . '"' : '') . ($f->max !== null ? ' max="' . FieldSpec::num($f->max) . '"' : '') . "' . \$attrs($n) . ' value=\"' . \$h->e(\$v($n)) . '\">');",
            'decimal' => "echo \$field($n, $L, '<input type=\"text\" inputmode=\"decimal\"$req' . \$attrs($n) . ' value=\"' . \$h->e(\$v($n)) . '\" placeholder=\"0.00\">');",
            'bool' => "echo \$field($n, $L, '<input type=\"hidden\" name=\"' . \$h->e($n) . '\" value=\"0\"><label class=\"row small\"><input type=\"checkbox\" value=\"1\"' . \$attrs($n) . (in_array(\$v($n), ['1', 'true'], true) ? ' checked' : '') . '> ' . \$h->t('Yes') . '</label>');",
            'date' => "echo \$field($n, $L, '<input type=\"date\"$req' . \$attrs($n) . ' value=\"' . \$h->e(\$v($n)) . '\">');",
            'datetime' => "echo \$field($n, $L, '<input type=\"datetime-local\"$req' . \$attrs($n) . ' value=\"' . \$h->e(\$v($n)) . '\">');",
            'enum' => "echo \$field($n, $L, '<select' . \$attrs($n) . '>' . \$options($rulesClass::{$f->constName()}_OPTIONS, \$v($n)) . '</select>');",
            'user' => "echo \$field($n, $L, '<input type=\"text\"' . \$attrs($n) . ' value=\"' . \$h->e(\$v($n)) . '\" placeholder=\"' . \$h->t(" . self::q($f->required ? 'User id — leave empty: yourself' : 'User id (optional)') . ") . '\" autocomplete=\"off\">');",
            'uuid' => "echo \$field($n, $L, '<input type=\"text\"$req' . \$attrs($n) . ' value=\"' . \$h->e(\$v($n)) . '\" autocomplete=\"off\">');",
        };
    }

    /** Escaped HTML for a list row value (array $r from the repository search). */
    public static function listCell(FieldSpec $f, string $statusEnum): string
    {
        $n = self::q($f->name);
        $v = "\$r[$n]";
        return match (true) {
            $f->isStatus() => "\$h->badge((string) $v, \$h->tr($statusEnum::from((string) $v)->label()))",
            $f->type === 'decimal' => "($v === null ? '—' : \$h->e(number_format((float) $v, 2, '.', ' ')))",
            $f->type === 'bool' => "(in_array($v, [true, 1, '1', 't', 'true'], true) ? \$h->t('Yes') : \$h->t('No'))",
            $f->type === 'datetime' => "\$h->datetime($v)",
            $f->type === 'user' => "\$h->e(\$r[" . self::q($f->name . '_name') . "] ?? '—')",
            $f->type === 'enum' => "\$h->e(\$h->tr(ucfirst(str_replace('_', ' ', (string) $v))))",
            default => "\$h->e($v ?? '—')",
        };
    }

    /** Escaped HTML for the detail page (entity getter). */
    public static function detailValue(FieldSpec $f, string $var): string
    {
        $g = "{$var}->{$f->camel()}()";
        return match (true) {
            $f->isStatus() => "\$h->badge({$g}->value, \$h->tr({$g}->label()))",
            $f->type === 'decimal' => "($g === null ? '—' : \$h->e(number_format((float) $g, 2, '.', ' ')))",
            $f->type === 'bool' => "($g ? \$h->t('Yes') : \$h->t('No'))",
            $f->type === 'datetime' => "\$h->datetime($g)",
            $f->type === 'text' => "($g === null ? '—' : nl2br(\$h->e($g)))",
            $f->type === 'enum' => "\$h->e(\$h->tr(ucfirst(str_replace('_', ' ', $g))))",
            $f->type === 'int' => "\$h->e($g === null ? '—' : (string) $g)",
            default => "\$h->e($g ?? '—')",
        };
    }

    /** Value for the edit form (string). */
    public static function formValue(FieldSpec $f, string $var): string
    {
        $g = "{$var}->{$f->camel()}()";
        return match (true) {
            $f->isStatus() => "{$g}->value",
            $f->type === 'bool' => "($g ? '1' : '0')",
            $f->type === 'datetime' => "($g === null ? '' : (new \\DateTimeImmutable($g))->format('Y-m-d\\TH:i'))",
            $f->type === 'int' => "($g === null ? '' : (string) $g)",
            default => "($g ?? '')",
        };
    }

    /** `new OA\Property(...)` for the OpenAPI schema. */
    public static function oaProperty(FieldSpec $f, bool $input = false): string
    {
        $p = ["property: " . self::q($f->name)];
        [$type, $extra] = match ($f->type) {
            'int' => ['integer', array_filter([$f->min !== null ? 'minimum: ' . FieldSpec::num($f->min) : null, $f->max !== null ? 'maximum: ' . FieldSpec::num($f->max) : null])],
            'decimal' => ['string', ["pattern: '^-?\\\\d{1,12}(\\\\.\\\\d{1,2})?$'", "example: '1250.50'"]],
            'bool' => ['boolean', []],
            'date' => ['string', ["format: 'date'"]],
            'datetime' => ['string', ["format: 'date-time'"]],
            'email' => ['string', ["format: 'email'", 'maxLength: 254']],
            'enum' => ['string', ['enum: [' . implode(', ', array_map([self::class, 'q'], $f->options)) . ']']],
            'user', 'uuid' => ['string', ["format: 'uuid'"]],
            'string', 'text' => ['string', ['maxLength: ' . $f->maxLength()]],
        };
        $p[] = "type: '$type'";
        array_push($p, ...$extra);
        if ($f->nullable() && !$input) {
            $p[] = 'nullable: true';
        }
        if ($input && $f->type === 'user') {
            $p[] = "description: 'Defaults to the caller when omitted'";
        }
        return 'new OA\\Property(' . implode(', ', $p) . ')';
    }

    /**
     * Valid sample value (PHP literal) for tests; null = omit (user fields default to the actor).
     * $variant gives a second distinct value (updates).
     */
    public static function sample(FieldSpec $f, int $variant = 0): ?string
    {
        $min = $f->min;
        $lo = (int) ($min ?? 0);
        $hi = $f->max ?? 1000000;
        return match ($f->type) {
            'string' => self::stringSample($f, $variant),
            'text' => self::q(self::fitString($variant === 0 ? 'Generated sample text.' : 'Updated sample text.', $f)),
            'email' => $f->unique
                ? "'sample-' . bin2hex(random_bytes(4)) . '@example.com'"
                : self::q($variant === 0 ? 'sample@example.com' : 'updated@example.com'),
            'int' => $f->unique
                ? 'random_int(' . $lo . ', ' . max($lo, (int) $hi) . ')'
                : (string) max($lo, min((int) $hi, $variant === 0 ? 42 : 43)),
            'decimal' => self::q(number_format(max((float) ($min ?? 0), min((float) ($f->max ?? 999999999), $variant === 0 ? 1250.5 : 99.9)), 2, '.', '')),
            'bool' => $variant === 0 ? 'true' : 'false',
            'date' => $f->unique
                ? "(new \\DateTimeImmutable('2026-01-01'))->modify('+' . random_int(0, 30000) . ' days')->format('Y-m-d')"
                : self::q($variant === 0 ? '2026-12-31' : '2027-01-15'),
            'datetime' => self::q($variant === 0 ? '2026-12-31T10:30:00+05:00' : '2027-01-15T08:00:00+05:00'),
            'enum' => self::q($f->options[$variant % count($f->options)]),
            'uuid' => $f->unique
                ? '\\App\\Shared\\Domain\\Uuid::v7()'
                : self::q($variant === 0 ? '018f2b6e-7a3c-7d1e-9b2a-1c2d3e4f5a6b' : '018f2b6e-7a3c-7d1e-9b2a-1c2d3e4f5a6c'),
            'user' => null,
        };
    }

    /** Distinct per test run (random suffix) so unique fields and searches stay independent. */
    private static function stringSample(FieldSpec $f, int $variant): string
    {
        $prefix = ($variant === 0 ? 'Sample ' : 'Updated ') . str_replace('_', ' ', $f->name) . ' ';
        $max = (int) $f->maxLength();
        $min = (int) ($f->min ?? 0);
        if ($max >= mb_strlen($prefix) + 8 && $min <= mb_strlen($prefix) + 8) {
            return self::q($prefix) . ' . bin2hex(random_bytes(4))';
        }
        return 'substr(bin2hex(random_bytes(16)), 0, ' . max(1, min(32, max($min, min($max, 12)))) . ')';
    }

    /** An invalid value (PHP literal) that the rules must reject, or null when the type has none. */
    public static function invalid(FieldSpec $f): ?string
    {
        return match ($f->type) {
            'string', 'text' => 'str_repeat(\'x\', ' . ($f->maxLength() + 1) . ')',
            'email' => self::q('not-an-email'),
            'int' => self::q('12.5x'),
            'decimal' => self::q('12.345'),
            'date' => self::q('2026-13-45'),
            'datetime' => self::q('not a date'),
            'enum' => self::q('bogus_option'),
            'user', 'uuid' => self::q('not-a-uuid'),
            'bool' => null,
        };
    }

    private static function fitString(string $s, FieldSpec $f): string
    {
        $max = $f->maxLength() ?? 255;
        $min = (int) ($f->min ?? 0);
        $s = mb_substr($s, 0, $max);
        return mb_strlen($s) < $min ? str_pad($s, $min, 'x') : $s;
    }
}
