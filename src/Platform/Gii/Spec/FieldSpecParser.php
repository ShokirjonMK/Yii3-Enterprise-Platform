<?php

declare(strict_types=1);

namespace App\Platform\Gii\Spec;

use InvalidArgumentException;

/**
 * Parses the field spec used by the console and the admin UI:
 *
 *   title:string:required:max=255,amount:decimal:min=0,status:enum(draft|active|closed),due_on:date,owner_id:user
 *
 * Grammar: fields separated by "," (or new lines); each field `name:type[:modifier]*`.
 *   types      string text int decimal bool date datetime email enum(a|b|c) user uuid
 *   modifiers  required, unique, index, max=N, min=N, default=V
 * Every problem is collected and reported at once ({@see GiiSpecException}).
 */
final class FieldSpecParser
{
    private const NAME = '/^[a-z][a-z0-9_]{1,39}$/';
    private const OPTION = '/^[a-z][a-z0-9_]{0,31}$/';
    public const MAX_FIELDS = 30;

    /** @return list<FieldSpec> */
    public static function parse(string $spec): array
    {
        $errors = [];
        $fields = [];
        $parts = preg_split('/[,\n\r]+/', trim($spec)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $p) => $p !== ''));
        if ($parts === []) {
            throw new GiiSpecException(['fields' => ['At least one field is required, e.g. "title:string:required".']]);
        }
        if (count($parts) > self::MAX_FIELDS) {
            throw new GiiSpecException(['fields' => [sprintf('At most %d fields are supported.', self::MAX_FIELDS)]]);
        }
        foreach ($parts as $part) {
            try {
                $field = self::field($part);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            if (isset($fields[$field->name])) {
                $errors[] = sprintf('Duplicate field "%s".', $field->name);
                continue;
            }
            $fields[$field->name] = $field;
        }
        $statuses = array_filter($fields, static fn(FieldSpec $f) => $f->name === 'status');
        foreach ($statuses as $s) {
            if ($s->type !== 'enum' || count($s->options) < 2) {
                $errors[] = 'Field "status" is the lifecycle: declare it as enum with at least two states, e.g. status:enum(draft|active|closed).';
            }
        }
        if ($errors !== []) {
            throw new GiiSpecException(['fields' => $errors]);
        }
        return array_values($fields);
    }

    private static function field(string $part): FieldSpec
    {
        // the enum option list may contain ":"-free tokens only; split the type off first
        if (!preg_match('/^([^:]+):(enum\(([^)]*)\)|[a-z]+)(.*)$/', $part, $m)) {
            throw new InvalidArgumentException(sprintf('"%s": expected name:type[:modifier...].', $part));
        }
        $name = trim($m[1]);
        $type = $m[2];
        $options = [];
        if (str_starts_with($type, 'enum(')) {
            $options = array_values(array_filter(array_map('trim', explode('|', $m[3])), static fn($o) => $o !== ''));
            $type = 'enum';
            if (count($options) < 1) {
                throw new InvalidArgumentException(sprintf('"%s": enum needs options, e.g. enum(draft|active).', $name));
            }
            foreach ($options as $o) {
                if (preg_match(self::OPTION, $o) !== 1) {
                    throw new InvalidArgumentException(sprintf('"%s": invalid enum option "%s" (lowercase letters, digits, "_").', $name, $o));
                }
            }
            if (count(array_unique($options)) !== count($options)) {
                throw new InvalidArgumentException(sprintf('"%s": duplicate enum options.', $name));
            }
        }
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid field name "%s" (snake_case: lowercase letters, digits, "_", 2–40 chars).', $name));
        }
        if (in_array($name, FieldSpec::RESERVED, true)) {
            throw new InvalidArgumentException(sprintf('Field name "%s" is reserved by the generated code.', $name));
        }
        if (!in_array($type, FieldSpec::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('"%s": unknown type "%s" (allowed: %s).', $name, $type, implode(', ', FieldSpec::TYPES)));
        }

        $required = $unique = $index = false;
        $max = $default = null;
        $min = null;
        $mods = array_values(array_filter(explode(':', ltrim($m[4], ':')), static fn($x) => $x !== ''));
        foreach ($mods as $mod) {
            [$key, $val] = array_pad(explode('=', $mod, 2), 2, null);
            switch ($key) {
                case 'required':
                    $required = true;
                    break;
                case 'unique':
                    if (!in_array($type, ['string', 'email', 'int', 'uuid', 'date'], true)) {
                        throw new InvalidArgumentException(sprintf('"%s": unique is supported for string, email, int, uuid and date.', $name));
                    }
                    $unique = true;
                    break;
                case 'index':
                    $index = true;
                    break;
                case 'max':
                case 'min':
                    if (!in_array($type, ['string', 'text', 'int', 'decimal'], true)) {
                        throw new InvalidArgumentException(sprintf('"%s": %s is supported for string, text, int and decimal.', $name, $key));
                    }
                    if ($val === null || !is_numeric($val)) {
                        throw new InvalidArgumentException(sprintf('"%s": %s needs a number, e.g. %s=10.', $name, $key, $key));
                    }
                    $num = str_contains($val, '.') ? (float) $val : (int) $val;
                    if (in_array($type, ['string', 'text'], true) && ($num < 1 || is_float($num))) {
                        throw new InvalidArgumentException(sprintf('"%s": %s for text fields is a positive whole length.', $name, $key));
                    }
                    if ($type === 'string' && $key === 'max' && $num > 10485760) {
                        throw new InvalidArgumentException(sprintf('"%s": max length too large.', $name));
                    }
                    if ($type === 'int' && is_float($num)) {
                        throw new InvalidArgumentException(sprintf('"%s": %s must be a whole number for int.', $name, $key));
                    }
                    if ($key === 'max') {
                        if (is_float($num)) {
                            throw new InvalidArgumentException(sprintf('"%s": max must be a whole number.', $name));
                        }
                        $max = (int) $num;
                    } else {
                        $min = $num;
                    }
                    break;
                case 'default':
                    if ($type === 'enum') {
                        if (!in_array((string) $val, $options, true)) {
                            throw new InvalidArgumentException(sprintf('"%s": default must be one of the enum options.', $name));
                        }
                    } elseif ($type === 'bool') {
                        if (!in_array($val, ['true', 'false'], true)) {
                            throw new InvalidArgumentException(sprintf('"%s": bool default is true or false.', $name));
                        }
                    } else {
                        throw new InvalidArgumentException(sprintf('"%s": default is supported for enum and bool.', $name));
                    }
                    $default = (string) $val;
                    break;
                default:
                    throw new InvalidArgumentException(sprintf('"%s": unknown modifier "%s" (required, unique, index, max=N, min=N, default=V).', $name, $mod));
            }
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException(sprintf('"%s": min is greater than max.', $name));
        }
        if ($name === 'status' && $type === 'enum' && $default !== null && $default !== $options[0]) {
            throw new InvalidArgumentException('"status": the first option is the initial state; do not set a different default.');
        }
        return new FieldSpec($name, $type, $required, $unique, $index, $max, $min, $options, $default);
    }
}
