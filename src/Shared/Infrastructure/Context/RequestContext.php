<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Context;

/**
 * Per-request (or per-job) ambient context: correlation id, actor, tenant and client info.
 * Populated by middleware / worker bootstrap, read by logging, audit and outbox.
 */
final class RequestContext
{
    private ?string $correlationId = null;
    private ?string $userId = null;
    private ?string $tenantId = null;
    private ?string $ip = null;
    private ?string $userAgent = null;
    private string $channel = 'cli';

    public function correlationId(): string
    {
        return $this->correlationId ??= self::generateId();
    }

    public function setCorrelationId(?string $id): void
    {
        $this->correlationId = $id !== null && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $id) === 1 ? $id : self::generateId();
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(?string $userId): void
    {
        $this->userId = $userId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setClient(?string $ip, ?string $userAgent): void
    {
        $this->ip = $ip;
        $this->userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, 500);
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): void
    {
        $this->channel = $channel;
    }

    public function reset(): void
    {
        $this->correlationId = $this->userId = $this->tenantId = $this->ip = $this->userAgent = null;
        $this->channel = 'cli';
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
