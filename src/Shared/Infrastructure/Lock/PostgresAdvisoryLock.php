<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Session-level PostgreSQL advisory lock. Held while the DB session lives, released automatically
 * if the process crashes (the connection is closed). Used by the scheduler so a job never runs twice
 * in parallel even if Redis is unavailable. TTL is not applicable (the lock lives with the connection).
 */
final class PostgresAdvisoryLock implements LockInterface
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function acquire(string $name, int $ttlSeconds): ?string
    {
        $ok = $this->db->createCommand('SELECT pg_try_advisory_lock(hashtextextended(:n, 0))', [':n' => $name])->queryScalar();
        return $ok === true || $ok === 't' || $ok === 1 ? $name : null;
    }

    public function release(string $name, string $token): bool
    {
        $ok = $this->db->createCommand('SELECT pg_advisory_unlock(hashtextextended(:n, 0))', [':n' => $name])->queryScalar();
        return $ok === true || $ok === 't' || $ok === 1;
    }

    public function refresh(string $name, string $token, int $ttlSeconds): bool
    {
        return true;
    }
}
