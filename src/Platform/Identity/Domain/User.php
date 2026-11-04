<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use DateTimeImmutable;

/**
 * User read model (hydrated from the `users` table). Mutations go through UserService.
 */
final readonly class User
{
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public ?string $organizationId,
        public string $username,
        public ?string $email,
        public ?string $phone,
        public string $fullName,
        public ?string $passwordHash,
        public string $status,
        public string $authPolicy,
        public bool $mustChangePassword,
        public ?DateTimeImmutable $passwordChangedAt,
        public bool $mfaEnabled,
        public bool $mfaRequired,
        public ?string $mfaSecretEnc,
        public array $mfaRecoveryCodes,
        public ?int $mfaLastUsedStep,
        public int $failedLogins,
        public ?DateTimeImmutable $lockedUntil,
        public ?DateTimeImmutable $lastLoginAt,
        public string $locale,
        public string $timezone,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $deletedAt,
        public int $version,
    ) {}

    public static function fromRow(array $r): self
    {
        $dt = static fn($v) => $v === null ? null : new DateTimeImmutable((string) $v);
        $codes = $r['mfa_recovery_codes'] ?? '[]';
        return new self(
            (string) $r['id'],
            $r['tenant_id'] ?? null,
            $r['organization_id'] ?? null,
            (string) $r['username'],
            $r['email'] ?? null,
            $r['phone'] ?? null,
            (string) ($r['full_name'] ?? ''),
            $r['password_hash'] ?? null,
            (string) $r['status'],
            (string) $r['auth_policy'],
            (bool) $r['must_change_password'],
            $dt($r['password_changed_at'] ?? null),
            (bool) $r['mfa_enabled'],
            (bool) $r['mfa_required'],
            $r['mfa_secret_enc'] ?? null,
            is_array($codes) ? $codes : (json_decode((string) $codes, true) ?: []),
            isset($r['mfa_last_used_step']) ? (int) $r['mfa_last_used_step'] : null,
            (int) $r['failed_logins'],
            $dt($r['locked_until'] ?? null),
            $dt($r['last_login_at'] ?? null),
            (string) $r['locale'],
            (string) $r['timezone'],
            new DateTimeImmutable((string) $r['created_at']),
            $dt($r['deleted_at'] ?? null),
            (int) $r['version'],
        );
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE && $this->deletedAt === null;
    }

    public function isLocked(?DateTimeImmutable $now = null): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > ($now ?? new DateTimeImmutable());
    }

    public function canUsePassword(): bool
    {
        return $this->authPolicy !== AuthPolicy::SSO_ONLY && $this->passwordHash !== null;
    }

    public function canUseSso(): bool
    {
        return $this->authPolicy !== AuthPolicy::LOCAL_ONLY;
    }

    public function displayName(): string
    {
        return $this->fullName !== '' ? $this->fullName : $this->username;
    }

    /** Safe array for audit/API (no secrets). */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'organization_id' => $this->organizationId,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'full_name' => $this->fullName,
            'status' => $this->status,
            'auth_policy' => $this->authPolicy,
            'mfa_enabled' => $this->mfaEnabled,
            'must_change_password' => $this->mustChangePassword,
            'locale' => $this->locale,
            'last_login_at' => $this->lastLoginAt?->format(DATE_ATOM),
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
