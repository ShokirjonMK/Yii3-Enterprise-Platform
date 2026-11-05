<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa\WebAuthn;

/** A CBOR byte string (distinct from a text string). */
final class Bytes
{
    public function __construct(public readonly string $value) {}
}
