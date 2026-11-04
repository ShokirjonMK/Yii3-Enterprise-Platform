<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Session;

use Predis\ClientInterface;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Stores PHP session payloads in Redis so that every stateless PHP instance shares sessions.
 *
 * Outage policy: sessions are FAIL-CLOSED. If Redis is unreachable an exception is raised and the
 * request is answered with 503; we never silently fall back to per-node storage, which would log
 * users out randomly or allow session fixation across nodes.
 */
final class RedisSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private const PREFIX = 'sess:';

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly int $ttl = 43200,
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $value = $this->redis->get(self::PREFIX . $id);
        return is_string($value) ? $value : '';
    }

    public function write(string $id, string $data): bool
    {
        $this->redis->setex(self::PREFIX . $id, $this->ttl, $data);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->redis->del([self::PREFIX . $id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis expires keys itself.
        return 0;
    }

    public function validateId(string $id): bool
    {
        // Strict mode: refuse unknown (attacker-supplied) session ids.
        return (bool) $this->redis->exists(self::PREFIX . $id);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->redis->expire(self::PREFIX . $id, $this->ttl);
        return true;
    }

    public function destroyById(string $id): void
    {
        $this->destroy($id);
    }
}
