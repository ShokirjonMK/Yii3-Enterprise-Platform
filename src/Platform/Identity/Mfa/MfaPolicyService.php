<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\User;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\ManagerInterface;

/**
 * Who must use two-factor authentication and with which methods.
 *
 *  - Methods are switched on globally (settings mfa.<method>_enabled) and must be configured (provider credentials).
 *  - Policies are per role (table mfa_policies); "*" applies to users whose roles have no policy of their own.
 *    A user with several roles gets: required = any role requires it, allowed = intersection of the allowed sets
 *    (never empty), remember-device days = the smallest value.
 *  - Policies of administrative roles can be changed only by a superadmin; the "*" default cannot be deleted.
 *
 * Author: ShokirjonMK
 */
final class MfaPolicyService
{
    public const DEFAULT = '*';

    /** @var array<string, array{required:bool, allowed:list<string>, remember_days:int}>|null */
    private ?array $policies = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettingsService $settings,
        private readonly ManagerInterface $rbac,
        private readonly ItemsStorageInterface $items,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
    ) {}

    /** Methods switched on by an administrator (regardless of provider configuration). */
    public function enabled(string $type): bool
    {
        try {
            return (bool) $this->settings->get("mfa.{$type}_enabled");
        } catch (Throwable) {
            return false;
        }
    }

    /** Provider credentials present for the method (TOTP and passkeys need none). */
    public function configured(string $type): bool
    {
        $s = fn(string $key): string => trim((string) ($this->settings->get($key) ?? ''));
        return match ($type) {
            MfaMethodType::TOTP, MfaMethodType::WEBAUTHN => true,
            MfaMethodType::TELEGRAM => $s('notifications.telegram_bot_token') !== '',
            MfaMethodType::EMAIL => $s('notifications.smtp_dsn') !== '' && !str_starts_with($s('notifications.smtp_dsn'), 'null://'),
            MfaMethodType::SMS => $s('notifications.sms_gateway_url') !== '' && $s('notifications.sms_gateway_token') !== '',
            MfaMethodType::VOICE => match ($s('mfa.voice_provider')) {
                'twilio' => $s('mfa.voice_twilio_sid') !== '' && $s('mfa.voice_twilio_token') !== '' && $s('mfa.voice_from') !== '',
                'http' => $s('mfa.voice_http_url') !== '' && $s('mfa.voice_http_token') !== '',
                default => false,
            },
            MfaMethodType::EIMZO => $s('mfa.eimzo_server_url') !== '',
            default => false,
        };
    }

    /** @return list<string> methods that are enabled and configured */
    public function available(): array
    {
        return array_values(array_filter(MfaMethodType::ALL, fn(string $t) => $this->enabled($t) && $this->configured($t)));
    }

    public function forUser(User $user): MfaPolicy
    {
        $policies = $this->all();
        try {
            $roles = array_keys($this->rbac->getRolesByUserId($user->id));
        } catch (Throwable) {
            $roles = [];
        }
        $applicable = array_values(array_intersect($roles, array_keys($policies)));
        if ($applicable === []) {
            $applicable = [self::DEFAULT];
        }
        $required = $user->mfaRequired;
        $requiredBy = $user->mfaRequired ? ['account'] : [];
        $allowed = null;
        $remember = PHP_INT_MAX;
        foreach ($applicable as $subject) {
            $p = $policies[$subject] ?? ['required' => false, 'allowed' => MfaMethodType::ALL, 'remember_days' => 0];
            $required = $required || $p['required'];
            if ($p['required']) {
                $requiredBy[] = $subject;
            }
            $allowed = $allowed === null ? $p['allowed'] : array_values(array_intersect($allowed, $p['allowed']));
            $remember = min($remember, $p['remember_days']);
        }
        if ($allowed === []) {
            $allowed = [MfaMethodType::TOTP]; // incompatible role policies: fall back to the authenticator app
        }
        $available = $this->available();
        $allowed = array_values(array_filter(MfaMethodType::ALL, fn(string $t) => in_array($t, (array) $allowed, true) && in_array($t, $available, true)));
        return new MfaPolicy($required, $allowed, $remember === PHP_INT_MAX ? 0 : $remember, $applicable, $requiredBy);
    }

    /** @return array<string, array{required:bool, allowed:list<string>, remember_days:int, updated_at?:string}> */
    public function all(): array
    {
        if ($this->policies !== null) {
            return $this->policies;
        }
        $out = [];
        try {
            foreach ((new Query($this->db))->from('mfa_policies')->orderBy(['subject' => SORT_ASC])->all() as $r) {
                $allowed = is_array($r['allowed']) ? $r['allowed'] : (json_decode((string) $r['allowed'], true) ?: []);
                $out[(string) $r['subject']] = [
                    'required' => (bool) $r['required'],
                    'allowed' => array_values(array_filter($allowed, [MfaMethodType::class, 'isValid'])),
                    'remember_days' => (int) $r['remember_days'],
                    'updated_at' => (string) $r['updated_at'],
                ];
            }
        } catch (Throwable) {
            // table not migrated yet: behave like "nobody is forced"
        }
        return $this->policies = $out;
    }

    /**
     * @param list<string> $allowed
     */
    public function save(string $actorId, string $subject, bool $required, array $allowed, int $rememberDays): void
    {
        $this->access->assert($actorId, 'auth.manage');
        if ($subject !== self::DEFAULT && !$this->items->roleExists($subject)) {
            throw new NotFoundException('Role not found.');
        }
        $this->assertMayEdit($actorId, $subject);
        $allowed = array_values(array_intersect(MfaMethodType::ALL, $allowed));
        if ($allowed === []) {
            throw ValidationException::single('allowed', 'Select at least one method.');
        }
        if ($rememberDays < 0 || $rememberDays > 90) {
            throw ValidationException::single('remember_days', 'Remember-device period must be between 0 and 90 days.');
        }
        $before = $this->all()[$subject] ?? null;
        $after = ['required' => $required, 'allowed' => $allowed, 'remember_days' => $rememberDays];
        $this->db->transaction(function () use ($actorId, $subject, $after, $before): void {
            $this->db->createCommand()->upsert('mfa_policies', [
                'subject' => $subject,
                'required' => $after['required'],
                'allowed' => json_encode($after['allowed']),
                'remember_days' => $after['remember_days'],
                'updated_at' => new Expression('now()'),
                'updated_by' => $actorId,
            ])->execute();
            unset($before['updated_at']);
            $this->audit->record(
                'auth.mfa_policy.saved',
                'mfa_policy',
                $subject,
                $before,
                $after,
                [],
                category: 'security',
                severity: ($before['required'] ?? false) && !$after['required'] ? 'warning' : 'notice',
                actorId: $actorId,
            );
        });
        $this->policies = null;
    }

    public function delete(string $actorId, string $subject): void
    {
        $this->access->assert($actorId, 'auth.manage');
        if ($subject === self::DEFAULT) {
            throw ValidationException::single('subject', 'The default policy cannot be deleted.');
        }
        $this->assertMayEdit($actorId, $subject);
        $before = $this->all()[$subject] ?? throw new NotFoundException('Policy not found.');
        $this->db->transaction(function () use ($actorId, $subject, $before): void {
            $this->db->createCommand()->delete('mfa_policies', ['subject' => $subject])->execute();
            unset($before['updated_at']);
            $this->audit->record('auth.mfa_policy.deleted', 'mfa_policy', $subject, $before, null, [], category: 'security', severity: 'warning', actorId: $actorId);
        });
        $this->policies = null;
    }

    public function mayEdit(string $actorId, string $subject): bool
    {
        return $subject === self::DEFAULT
            ? $this->access->isSuperadmin($actorId) || $this->access->can($actorId, 'auth.manage')
            : !$this->access->isAdminRole($subject) || $this->access->isSuperadmin($actorId);
    }

    private function assertMayEdit(string $actorId, string $subject): void
    {
        if (!$this->mayEdit($actorId, $subject)) {
            throw new AccessDeniedException('Only a superadmin can change the two-factor policy of an administrative role.');
        }
    }
}
