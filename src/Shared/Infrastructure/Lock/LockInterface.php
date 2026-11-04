<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

interface LockInterface
{
    /**
     * Attempts to acquire the named lock without blocking.
     *
     * @return string|null An opaque owner token on success (needed for release), null when held by someone else.
     */
    public function acquire(string $name, int $ttlSeconds): ?string;

    public function release(string $name, string $token): bool;

    /**
     * Extends a held lock (for long-running jobs). Returns false when the lock was lost.
     */
    public function refresh(string $name, string $token, int $ttlSeconds): bool;
}
