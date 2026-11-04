<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database;

use Yiisoft\Db\Query\QueryInterface;

/**
 * Offset pagination + whitelisted sorting for list screens and API collections.
 * Sort fields are NEVER taken verbatim from input: only keys of $sortable are accepted.
 */
final class Paginator
{
    /**
     * @param array<string, string> $sortable public sort key => SQL column expression
     */
    public static function paginate(
        QueryInterface $query,
        int $page,
        int $perPage,
        ?string $sort,
        array $sortable,
        string $defaultSort = '-created_at',
    ): Page {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));

        $total = (int) (clone $query)->count();

        $sort = $sort !== null && $sort !== '' ? $sort : $defaultSort;
        $desc = str_starts_with($sort, '-');
        $key = ltrim($sort, '-');
        if (!isset($sortable[$key])) {
            $desc = str_starts_with($defaultSort, '-');
            $key = ltrim($defaultSort, '-');
        }
        if (isset($sortable[$key])) {
            $query->orderBy([$sortable[$key] => $desc ? SORT_DESC : SORT_ASC]);
        }

        $items = $query->limit($perPage)->offset(($page - 1) * $perPage)->all();

        return new Page($items, $total, $page, $perPage, ($desc ? '-' : '') . $key);
    }
}
