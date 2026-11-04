<?php

declare(strict_types=1);

namespace App\Platform\Database;

use Yiisoft\Db\Expression\Expression;

/**
 * Case-insensitive "contains" over several columns with an explicitly bound, escaped LIKE pattern.
 * (Used by the runtime sections instead of Sql::search(), whose pattern is double-wrapped by the
 * query builder's LIKE operator in the installed yiisoft/db version.)
 */
final class SqlSearch
{
    /**
     * @param list<string> $columns trusted column names (never user input)
     */
    public static function contains(string $term, array $columns): array
    {
        $like = '%' . self::escape(mb_strtolower(trim($term))) . '%';
        $or = ['or'];
        foreach (array_values($columns) as $i => $column) {
            if (!preg_match('/^[a-z_][a-z0-9_.]*$/', $column)) {
                throw new \InvalidArgumentException('Invalid column name.');
            }
            $or[] = new Expression("lower($column::text) LIKE :s$i ESCAPE '!'", [":s$i" => $like]);
        }
        return $or;
    }

    /** Escapes LIKE wildcards with "!" (a backslash escape char confuses PDO's placeholder parser). */
    public static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** "starts with" condition for a trusted column. */
    public static function startsWith(string $column, string $prefix): Expression
    {
        if (!preg_match('/^[a-z_][a-z0-9_.]*$/', $column)) {
            throw new \InvalidArgumentException('Invalid column name.');
        }
        return new Expression("$column LIKE :sw ESCAPE '!'", [':sw' => self::escape($prefix) . '%']);
    }
}
