<?php

declare(strict_types=1);

namespace App\Platform\Gii\Spec;

/**
 * One attribute of a generated entity, parsed from the field spec
 * (`name:type[:modifier...]`, e.g. `amount:decimal:min=0`, `status:enum(draft|active|closed)`).
 *
 * An enum field named `status` becomes the lifecycle state machine of the entity
 * (forward-only transitions, the last option is terminal / read-only).
 */
final class FieldSpec
{
    public const TYPES = ['string', 'text', 'int', 'decimal', 'bool', 'date', 'datetime', 'email', 'enum', 'user', 'uuid'];

    /** Column / property names managed by the generated code itself. */
    public const RESERVED = [
        'id', 'tenant_id', 'version', 'created_by', 'created_at', 'updated_at', 'deleted_at', 'search', 'q', 'page',
        'per_page', 'sort', 'filter', 'scope', 'csrf', 'events', 'values', 'value', 'label', 'record', 'create', 'update',
        'from_row', 'to_row', 'to_array', 'pull_events', 'mark_deleted', 'change_status', 'assert_editable', 'is_deleted',
        'hydrate', 'display_title', 'tenant', 'class', 'function', 'list', 'array', 'default', 'match', 'static', 'self', 'parent',
    ];

    /** @param list<string> $options enum options */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly bool $unique = false,
        public readonly bool $index = false,
        public readonly ?int $max = null,
        public readonly int|float|null $min = null,
        public readonly array $options = [],
        public readonly ?string $default = null,
    ) {}

    public function isStatus(): bool
    {
        return $this->type === 'enum' && $this->name === 'status';
    }

    /** NULL is a legal stored value (optional field without a natural default). */
    public function nullable(): bool
    {
        return !$this->required && !in_array($this->type, ['bool', 'enum'], true);
    }

    public function pascal(): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $this->name)));
    }

    public function camel(): string
    {
        return lcfirst($this->pascal());
    }

    public function constName(): string
    {
        return strtoupper($this->name);
    }

    /** Human label: `due_on` → "Due on", `owner_id` (user) → "Owner". */
    public function label(): string
    {
        $n = $this->name;
        if (in_array($this->type, ['user', 'uuid'], true) && str_ends_with($n, '_id')) {
            $n = substr($n, 0, -3);
        }
        return ucfirst(str_replace('_', ' ', $n));
    }

    public function maxLength(): ?int
    {
        return match ($this->type) {
            'string' => $this->max ?? 255,
            'text' => $this->max ?? 10000,
            'email' => 254,
            default => null,
        };
    }

    public function defaultOption(): string
    {
        return $this->default ?? ($this->options[0] ?? '');
    }

    /** PHP type of the normalised value (getter return type). */
    public function phpType(): string
    {
        $t = match ($this->type) {
            'int' => 'int',
            'bool' => 'bool',
            default => 'string',
        };
        return $this->nullable() ? '?' . $t : $t;
    }

    public function isSearchable(): bool
    {
        return in_array($this->type, ['string', 'text', 'email'], true);
    }

    public function isSortable(): bool
    {
        return !in_array($this->type, ['text', 'user', 'uuid'], true);
    }

    /** Exact-match list filter (comma-separated values for enums). */
    public function isFilterable(): bool
    {
        return in_array($this->type, ['enum', 'user', 'bool'], true);
    }

    /** Shown in list tables. */
    public function isListed(): bool
    {
        return !in_array($this->type, ['text', 'uuid'], true);
    }

    /** @return list<string> PascalCase enum case names */
    public function caseNames(): array
    {
        return array_map(static fn(string $o) => str_replace(' ', '', ucwords(str_replace('_', ' ', $o))), $this->options);
    }

    public function sqlType(): string
    {
        return match ($this->type) {
            'string' => 'varchar(' . $this->maxLength() . ')',
            'email' => 'varchar(254)',
            'text' => 'text',
            'int' => 'integer',
            'decimal' => 'numeric(14,2)',
            'bool' => 'boolean',
            'date' => 'date',
            'datetime' => 'timestamptz',
            'enum' => 'varchar(32)',
            'user', 'uuid' => 'uuid',
        };
    }

    /** Column definition (without the name). */
    public function sqlColumn(): string
    {
        $sql = $this->sqlType();
        if ($this->type === 'bool') {
            $sql .= ' NOT NULL DEFAULT ' . ($this->default === 'true' ? 'true' : 'false');
        } elseif ($this->type === 'enum') {
            $sql .= " NOT NULL DEFAULT '" . $this->defaultOption() . "'";
        } elseif ($this->required) {
            $sql .= ' NOT NULL';
        }
        if ($this->type === 'user') {
            $sql .= ' REFERENCES users (id) ON DELETE ' . ($this->required ? 'RESTRICT' : 'SET NULL');
        }
        return $sql;
    }

    /** @return array<string,string> constraint name => CHECK expression */
    public function sqlChecks(string $table): array
    {
        $n = $this->name;
        $null = $this->nullable() ? "$n IS NULL OR " : '';
        $c = [];
        if ($this->type === 'enum') {
            $c["{$table}_{$n}_chk"] = "$n IN (" . implode(', ', array_map(static fn($o) => "'$o'", $this->options)) . ')';
        }
        if (in_array($this->type, ['string', 'text'], true)) {
            if ($this->required) {
                $c["{$table}_{$n}_not_blank"] = "btrim($n) <> ''";
            }
            if ($this->type === 'text' && $this->maxLength() !== null) {
                $c["{$table}_{$n}_len_chk"] = "{$null}char_length($n) <= " . $this->maxLength();
            }
            if ($this->min !== null) {
                $c["{$table}_{$n}_min_len_chk"] = "{$null}char_length($n) >= " . (int) $this->min;
            }
        }
        if (in_array($this->type, ['int', 'decimal'], true)) {
            if ($this->min !== null) {
                $c["{$table}_{$n}_min_chk"] = "{$null}$n >= " . self::num($this->min);
            }
            if ($this->max !== null) {
                $c["{$table}_{$n}_max_chk"] = "{$null}$n <= " . self::num($this->max);
            }
        }
        if ($this->type === 'email') {
            $c["{$table}_{$n}_email_chk"] = "{$null}position('@' in $n) > 1";
        }
        return $c;
    }

    public static function num(int|float $v): string
    {
        return is_int($v) ? (string) $v : rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
