<?php

declare(strict_types=1);

namespace App\Shared\Domain;

final class Uuid
{
    /** RFC 9562 UUIDv7 (time-ordered → index-friendly). */
    public static function v7(): string
    {
        $ms = (int) (microtime(true) * 1000);
        $b = random_bytes(16);
        $time = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
        $hex = $time . bin2hex(substr($b, 6));
        $hex[12] = '7';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    public static function isValid(?string $v): bool
    {
        return $v !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }
}
