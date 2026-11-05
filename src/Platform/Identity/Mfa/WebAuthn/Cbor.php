<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa\WebAuthn;

use InvalidArgumentException;

/**
 * Minimal CBOR (RFC 8949) decoder for WebAuthn attestation objects and COSE keys: unsigned/negative integers,
 * byte/text strings, arrays, maps, simple values (false/true/null), definite and indefinite lengths. Floats and
 * tags are not needed by WebAuthn and are rejected/ignored. Byte strings are returned as Cbor\Bytes so they
 * are distinguishable from text.
 *
 * Author: ShokirjonMK
 */
final class Cbor
{
    private int $pos = 0;

    private function __construct(private readonly string $data) {}

    /** Decodes one item; $consumed receives the number of bytes read (authData + trailing extensions). */
    public static function decode(string $data, ?int &$consumed = null): mixed
    {
        $d = new self($data);
        $value = $d->item(0);
        $consumed = $d->pos;
        return $value;
    }

    private function item(int $depth): mixed
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('CBOR nesting too deep.');
        }
        $initial = $this->byte();
        $major = $initial >> 5;
        $info = $initial & 0x1f;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new InvalidArgumentException('Unsupported CBOR simple/float value.'),
            };
        }
        if ($info === 31) { // indefinite length
            return match ($major) {
                2, 3 => $this->indefiniteString($major, $depth),
                4 => $this->indefiniteArray($depth),
                5 => $this->indefiniteMap($depth),
                default => throw new InvalidArgumentException('Invalid indefinite CBOR item.'),
            };
        }
        $len = $this->length($info);
        return match ($major) {
            0 => $len,
            1 => -1 - $len,
            2 => new Bytes($this->read($len)),
            3 => $this->read($len),
            4 => $this->array($len, $depth),
            5 => $this->map($len, $depth),
            6 => $this->item($depth + 1), // tag: ignore, return tagged value
            default => throw new InvalidArgumentException('Invalid CBOR major type.'),
        };
    }

    private function length(int $info): int
    {
        return match (true) {
            $info < 24 => $info,
            $info === 24 => $this->byte(),
            $info === 25 => unpack('n', $this->read(2))[1],
            $info === 26 => unpack('N', $this->read(4))[1],
            $info === 27 => (static function (string $b): int {
                $v = unpack('J', $b)[1];
                if ($v < 0) {
                    throw new InvalidArgumentException('CBOR integer too large.');
                }
                return $v;
            })($this->read(8)),
            default => throw new InvalidArgumentException('Invalid CBOR length.'),
        };
    }

    private function array(int $len, int $depth): array
    {
        $out = [];
        for ($i = 0; $i < $len; $i++) {
            $out[] = $this->item($depth + 1);
        }
        return $out;
    }

    private function map(int $len, int $depth): array
    {
        $out = [];
        for ($i = 0; $i < $len; $i++) {
            $key = $this->item($depth + 1);
            $out[$key instanceof Bytes ? $key->value : $key] = $this->item($depth + 1);
        }
        return $out;
    }

    private function indefiniteArray(int $depth): array
    {
        $out = [];
        while (ord($this->data[$this->pos] ?? "\xff") !== 0xff) {
            $out[] = $this->item($depth + 1);
        }
        $this->pos++;
        return $out;
    }

    private function indefiniteMap(int $depth): array
    {
        $out = [];
        while (ord($this->data[$this->pos] ?? "\xff") !== 0xff) {
            $key = $this->item($depth + 1);
            $out[$key instanceof Bytes ? $key->value : $key] = $this->item($depth + 1);
        }
        $this->pos++;
        return $out;
    }

    private function indefiniteString(int $major, int $depth): Bytes|string
    {
        $buf = '';
        while (ord($this->data[$this->pos] ?? "\xff") !== 0xff) {
            $chunk = $this->item($depth + 1);
            $buf .= $chunk instanceof Bytes ? $chunk->value : (string) $chunk;
        }
        $this->pos++;
        return $major === 2 ? new Bytes($buf) : $buf;
    }

    private function byte(): int
    {
        if ($this->pos >= strlen($this->data)) {
            throw new InvalidArgumentException('Unexpected end of CBOR data.');
        }
        return ord($this->data[$this->pos++]);
    }

    private function read(int $n): string
    {
        if ($n < 0 || $this->pos + $n > strlen($this->data)) {
            throw new InvalidArgumentException('Unexpected end of CBOR data.');
        }
        $s = substr($this->data, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }
}
