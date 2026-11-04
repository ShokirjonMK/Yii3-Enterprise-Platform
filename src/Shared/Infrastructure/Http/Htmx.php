<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Psr\Http\Message\ServerRequestInterface;

final class Htmx
{
    public static function isRequest(?ServerRequestInterface $request): bool
    {
        return $request !== null && $request->getHeaderLine('HX-Request') === 'true';
    }

    /** History-restore requests need the full page. */
    public static function isPartial(?ServerRequestInterface $request): bool
    {
        return self::isRequest($request) && $request->getHeaderLine('HX-History-Restore-Request') !== 'true';
    }

    public static function target(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine('HX-Target');
    }
}
