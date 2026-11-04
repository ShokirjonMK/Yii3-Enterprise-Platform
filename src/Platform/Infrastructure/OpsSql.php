<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure;

use Yiisoft\Db\Expression\Expression;

/**
 * Case-insensitive "contains" filter for the operations sections. The query builder wraps the term
 * in % and escapes LIKE wildcards in it, so user input is always matched literally.
 * (Used instead of Shared Sql::search, whose explicit "%term%" gets its % escaped by yiisoft/db 2.)
 */
final class OpsSql
{
    /** @param list<string> $columns */
    public static function contains(string $term, array $columns): array
    {
        $term = trim($term);
        $or = ['or'];
        foreach ($columns as $c) {
            $or[] = ['ilike', new Expression($c . '::text'), $term];
        }
        return $or;
    }
}
