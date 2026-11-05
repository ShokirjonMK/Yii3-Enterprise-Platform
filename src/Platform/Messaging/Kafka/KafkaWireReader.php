<?php

declare(strict_types=1);

namespace App\Platform\Messaging\Kafka;

use RuntimeException;

/**
 * Big-endian reader for Kafka protocol responses (non-flexible versions). Used by KafkaTopicCreator.
 *
 * Author: ShokirjonMK
 */
final class KafkaWireReader
{
    private int $pos = 0;

    public function __construct(private readonly string $data) {}

    public function int16(): int
    {
        $v = unpack('n', $this->take(2))[1];
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    public function int32(): int
    {
        $v = unpack('N', $this->take(4))[1];
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    /** Nullable string (length -1 = null). */
    public function string(): ?string
    {
        $len = $this->int16();
        return $len < 0 ? null : $this->take($len);
    }

    private function take(int $n): string
    {
        if ($this->pos + $n > strlen($this->data)) {
            throw new RuntimeException('Truncated Kafka response.');
        }
        $s = substr($this->data, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }
}
