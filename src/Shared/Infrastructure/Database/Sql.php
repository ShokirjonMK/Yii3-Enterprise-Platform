<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database;

use Yiisoft\Db\Expression\Expression;

final class Sql
{
    public static function escapeLike(string $v): string
    {
        return addcslashes($v, '%_\\');
    }

    /**
     * Case-insensitive "contains" condition over several columns (uses pg_trgm indexes when present).
     * yiisoft/db's LIKE condition escapes the value and wraps it in % itself — pass the plain term.
     *
     * @param list<string> $columns
     */
    public static function search(string $term, array $columns): array
    {
        $needle = mb_strtolower(trim($term));
        $or = ['or'];
        foreach ($columns as $c) {
            $or[] = ['like', new Expression("lower($c::text)"), $needle];
        }
        return $or;
    }

    /** Encodes array values as JSON for jsonb columns. */
    public static function row(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $data;
    }

    public static function json(mixed $v, mixed $default = []): mixed
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_array($v)) {
            return $v;
        }
        $d = json_decode((string) $v, true);
        return $d ?? $default;
    }

    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === 't' || $v === 'true' || $v === '1';
    }
}
