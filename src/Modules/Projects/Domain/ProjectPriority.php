<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

enum ProjectPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $p) => $p->value, self::cases());
    }
}
