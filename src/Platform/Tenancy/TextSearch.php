<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use Yiisoft\Db\Expression\Expression;

/**
 * Case-insensitive "contains" condition over several columns for the identity admin sections (02–06).
 *
 * Note: yiisoft/db's ['like', col, value] condition escapes and wraps the value itself, so a value that is already
 * wrapped in % (as App\Shared\Infrastructure\Database\Sql::search() does) matches the literal "%" characters only.
 * This helper builds the LIKE explicitly with bound parameters instead.
 */
final class TextSearch
{
    private static int $seq = 0;

    /** @param list<string> $columns trusted SQL column expressions (never user input) */
    public static function condition(string $term, array $columns): Expression
    {
        $like = '%' . addcslashes(mb_strtolower(trim($term)), '%_\\') . '%';
        $parts = [];
        $params = [];
        foreach ($columns as $column) {
            $name = ':ts' . (++self::$seq);
            $parts[] = "lower(($column)::text) LIKE $name";
            $params[$name] = $like;
        }
        return new Expression('(' . implode(' OR ', $parts) . ')', $params);
    }
}
