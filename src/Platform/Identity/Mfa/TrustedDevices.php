<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

use SensitiveParameter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * "Remember this device for N days": after a successful second factor the browser gets a random token
 * (HttpOnly, Secure, SameSite=Lax cookie); only its SHA-256 is stored. The token is bound to the user,
 * expires after the policy period and is revoked when the user changes the password, resets 2FA or removes it.
 *
 * Author: ShokirjonMK
 */
final class TrustedDevices
{
    public const COOKIE = 'yep_mfa_device';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function issue(string $userId, int $days, string $userAgent, ?string $ip): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->createCommand()->insert('mfa_trusted_devices', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'user_agent' => mb_substr($userAgent, 0, 255),
            'ip' => $ip,
            'expires_at' => new Expression("now() + make_interval(days => :d)", [':d' => max(1, min(90, $days))]),
        ])->execute();
        return $token;
    }

    /** True when the cookie token belongs to the user, is not expired and the policy still allows remembering. */
    public function isTrusted(string $userId, #[SensitiveParameter] ?string $token, int $policyDays): bool
    {
        if ($policyDays <= 0 || $token === null || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return false;
        }
        $id = (new Query($this->db))->select('id')->from('mfa_trusted_devices')
            ->where(['token_hash' => hash('sha256', $token), 'user_id' => $userId])
            ->andWhere(new Expression('expires_at > now()'))
            // a shorter policy set later also shortens already issued tokens
            ->andWhere(new Expression('created_at > now() - make_interval(days => :d)', [':d' => $policyDays]))
            ->scalar();
        if ($id === false || $id === null) {
            return false;
        }
        $this->db->createCommand()->update('mfa_trusted_devices', ['last_used_at' => new Expression('now()')], ['id' => $id])->execute();
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function forUser(string $userId): array
    {
        return (new Query($this->db))->select(['id', 'user_agent', 'ip', 'created_at', 'expires_at', 'last_used_at'])->from('mfa_trusted_devices')
            ->where(['user_id' => $userId])->andWhere(new Expression('expires_at > now()'))->orderBy(['created_at' => SORT_DESC])->all();
    }

    public function revoke(string $userId, string $id): bool
    {
        return preg_match('/^[0-9a-f-]{36}$/', $id) === 1
            && $this->db->createCommand()->delete('mfa_trusted_devices', ['id' => $id, 'user_id' => $userId])->execute() > 0;
    }

    public function revokeAll(string $userId): int
    {
        return $this->db->createCommand()->delete('mfa_trusted_devices', ['user_id' => $userId])->execute();
    }
}
