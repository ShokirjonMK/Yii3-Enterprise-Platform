<?php

declare(strict_types=1);

namespace App\Modules\MultiDb\Domain;

/**
 * One editable attribute of a dataset. The type drives validation, form widgets, the API schema and how the value
 * is stored by each engine (e.g. "tags" is a native array in MongoDB, Array(String) in ClickHouse, JSON in SQL).
 *
 * Author: ShokirjonMK
 */
final readonly class Field
{
    public const STRING = 'string';
    public const TEXT = 'text';
    public const INT = 'int';
    public const DECIMAL = 'decimal';
    public const BOOL = 'bool';
    public const DATE = 'date';
    public const DATETIME = 'datetime';
    public const ENUM = 'enum';
    public const TAGS = 'tags';

    /** @param list<string> $options allowed values of an ENUM field */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = self::STRING,
        public bool $required = false,
        public int $max = 255,
        public array $options = [],
        public ?float $min = null,
        public string $hint = '',
    ) {}

    /**
     * Validates and converts a raw value (form string, JSON scalar/array, CLI string) to the PHP value that is stored.
     *
     * @return array{0:mixed, 1:?string} [value, error]
     */
    public function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        $empty = $raw === null || $raw === '' || $raw === [];
        if ($empty && $this->type !== self::BOOL) {
            return $this->required ? [null, $this->label . ' is required.'] : [$this->type === self::TAGS ? [] : null, null];
        }
        switch ($this->type) {
            case self::STRING:
            case self::TEXT:
                if (!is_scalar($raw)) {
                    return [null, $this->label . ' must be text.'];
                }
                $s = (string) $raw;
                return mb_strlen($s) > $this->max ? [null, $this->label . " must be at most {$this->max} characters."] : [$s, null];
            case self::INT:
                if (!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d{1,12}$/', $raw))) {
                    return [null, $this->label . ' must be a whole number.'];
                }
                $i = (int) $raw;
                return $this->min !== null && $i < $this->min ? [null, $this->label . ' must be at least ' . (int) $this->min . '.'] : [$i, null];
            case self::DECIMAL:
                $s = is_int($raw) || is_float($raw) ? (string) $raw : (is_string($raw) ? str_replace([' ', ','], ['', '.'], $raw) : '');
                if (!preg_match('/^-?\d{1,12}(\.\d{1,2})?$/', $s)) {
                    return [null, $this->label . ' must be a number with at most 2 decimals.'];
                }
                return $this->min !== null && (float) $s < $this->min ? [null, $this->label . ' must be at least ' . $this->min . '.'] : [$s, null];
            case self::BOOL:
                return [in_array($raw, [true, 1, '1', 'true', 'on', 'yes'], true), null];
            case self::DATE:
                $d = is_string($raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) : false;
                return $d === false || $d->format('Y-m-d') !== $raw ? [null, $this->label . ' must be a date (YYYY-MM-DD).'] : [$raw, null];
            case self::DATETIME:
                if (!is_string($raw)) {
                    return [null, $this->label . ' must be a date and time.'];
                }
                try {
                    return [(new \DateTimeImmutable(str_replace('T', ' ', $raw)))->format('Y-m-d H:i:s'), null];
                } catch (\Exception) {
                    return [null, $this->label . ' must be a date and time (YYYY-MM-DD HH:MM).'];
                }
            case self::ENUM:
                return in_array($raw, $this->options, true) ? [$raw, null] : [null, $this->label . ' must be one of: ' . implode(', ', $this->options) . '.'];
            case self::TAGS:
                $list = is_array($raw) ? $raw : explode(',', (string) $raw);
                $tags = [];
                foreach ($list as $t) {
                    if (!is_scalar($t)) {
                        return [null, $this->label . ' must be a list of words.'];
                    }
                    $t = mb_strtolower(trim((string) $t));
                    if ($t !== '') {
                        $tags[$t] = $t;
                    }
                }
                if (count($tags) > 20) {
                    return [null, $this->label . ' allows at most 20 tags.'];
                }
                foreach ($tags as $t) {
                    if (mb_strlen($t) > 40) {
                        return [null, 'Each tag must be at most 40 characters.'];
                    }
                }
                return $this->required && $tags === [] ? [null, $this->label . ' is required.'] : [array_values($tags), null];
        }
        return [null, 'Unknown field type.'];
    }

    /** Converts a stored value (any engine) back to a plain PHP / JSON value. */
    public function present(mixed $v): mixed
    {
        if ($v === null) {
            return $this->type === self::TAGS ? [] : null;
        }
        return match ($this->type) {
            self::INT => (int) $v,
            self::DECIMAL => number_format((float) $v, 2, '.', ''),
            self::BOOL => in_array($v, [true, 1, '1', 't', 'true'], true),
            self::DATE => substr((string) $v, 0, 10),
            self::DATETIME => substr(str_replace('T', ' ', (string) $v), 0, 19),
            self::TAGS => is_array($v) ? array_values(array_map('strval', $v)) : (array) (json_decode((string) $v, true) ?: []),
            default => (string) $v,
        };
    }

    /** Value for a form input (string). */
    public function formValue(mixed $v): string
    {
        $v = $this->present($v);
        return match (true) {
            is_array($v) => implode(', ', $v),
            is_bool($v) => $v ? '1' : '',
            $this->type === self::DATETIME && is_string($v) => str_replace(' ', 'T', substr($v, 0, 16)),
            default => (string) $v,
        };
    }

    /** Human readable value for lists and detail pages. */
    public function display(mixed $v): string
    {
        $v = $this->present($v);
        return match (true) {
            is_array($v) => $v === [] ? '—' : implode(', ', $v),
            is_bool($v) => $v ? 'Yes' : 'No',
            $v === null || $v === '' => '—',
            default => (string) $v,
        };
    }

    /** OpenAPI property type. */
    public function openApiType(): array
    {
        return match ($this->type) {
            self::INT => ['type' => 'integer'],
            self::DECIMAL => ['type' => 'string', 'pattern' => '^-?\d+(\.\d{1,2})?$'],
            self::BOOL => ['type' => 'boolean'],
            self::DATE => ['type' => 'string', 'format' => 'date'],
            self::DATETIME => ['type' => 'string', 'example' => '2026-11-06 10:30:00'],
            self::ENUM => ['type' => 'string', 'enum' => $this->options],
            self::TAGS => ['type' => 'array', 'items' => ['type' => 'string']],
            default => ['type' => 'string', 'maxLength' => $this->max],
        };
    }
}
