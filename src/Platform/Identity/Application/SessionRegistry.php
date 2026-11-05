<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Server-side registry of interactive sessions (independent of the Redis payload store):
 * listing active sessions, remote revocation, idle/absolute timeouts, MFA/re-auth timestamps.
 * Session ids are stored as SHA-256 hashes — a database leak does not leak usable session ids.
 */
final class SessionRegistry
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly int $idleTimeout = 1800,
        private readonly int $absoluteTimeout = 43200,
        private readonly ?SecurityPolicy $policy = null,   // admin-managed timeouts (settings) override the defaults
    ) {}

    public function idle(): int
    {
        return $this->policy?->sessionIdleSeconds() ?? $this->idleTimeout;
    }

    public function absolute(): int
    {
        return $this->policy?->sessionAbsoluteSeconds() ?? $this->absoluteTimeout;
    }

    public static function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function start(string $sessionId, string $userId, string $method, ?string $ip, ?string $ua, bool $mfaVerified): void
    {
        $this->db->createCommand()->upsert('user_sessions', [
            'id' => self::hash($sessionId),
            'user_id' => $userId,
            'auth_method' => $method,
            'ip' => $ip,
            'user_agent' => $ua === null ? null : mb_substr($ua, 0, 500),
            'expires_at' => (new DateTimeImmutable("+{$this->absolute()} seconds"))->format(DATE_ATOM),
            'mfa_verified_at' => $mfaVerified ? new Expression('now()') : null,
            'reauth_at' => new Expression('now()'),
        ])->execute();
    }

    /**
     * @return array{valid:bool, reason:?string, row:?array}
     */
    public function validate(string $sessionId, string $userId): array
    {
        $row = (new Query($this->db))->from('user_sessions')->where(['id' => self::hash($sessionId)])->one();
        if ($row === null || $row['user_id'] !== $userId) {
            return ['valid' => false, 'reason' => 'unknown_session', 'row' => null];
        }
        if ($row['revoked_at'] !== null) {
            return ['valid' => false, 'reason' => 'revoked', 'row' => $row];
        }
        $now = new DateTimeImmutable();
        if (new DateTimeImmutable((string) $row['expires_at']) < $now) {
            return ['valid' => false, 'reason' => 'absolute_timeout', 'row' => $row];
        }
        if ((new DateTimeImmutable((string) $row['last_seen_at']))->getTimestamp() + $this->idle() < $now->getTimestamp()) {
            return ['valid' => false, 'reason' => 'idle_timeout', 'row' => $row];
        }
        return ['valid' => true, 'reason' => null, 'row' => $row];
    }

    /** Throttled heartbeat (max once per minute) to keep DB writes low. */
    public function touch(string $sessionId, array $row): void
    {
        if ((new DateTimeImmutable((string) $row['last_seen_at']))->getTimestamp() > time() - 60) {
            return;
        }
        $this->db->createCommand()->update('user_sessions', ['last_seen_at' => new Expression('now()')], ['id' => self::hash($sessionId)])->execute();
    }

    public function markReauthenticated(string $sessionId): void
    {
        $this->db->createCommand()->update('user_sessions', ['reauth_at' => new Expression('now()'), 'mfa_verified_at' => new Expression('now()')], ['id' => self::hash($sessionId)])->execute();
    }

    public function reauthAge(string $sessionId): ?int
    {
        $v = (new Query($this->db))->select(new Expression('EXTRACT(EPOCH FROM now() - reauth_at)::int'))
            ->from('user_sessions')->where(['id' => self::hash($sessionId)])->scalar();
        return $v === false || $v === null ? null : (int) $v;
    }

    public function revoke(string $hashedId, string $reason): bool
    {
        return $this->db->createCommand()->update('user_sessions', ['revoked_at' => new Expression('now()'), 'revoked_reason' => $reason], ['id' => $hashedId, 'revoked_at' => null])->execute() > 0;
    }

    public function revokeCurrent(string $sessionId, string $reason): void
    {
        $this->revoke(self::hash($sessionId), $reason);
    }

    public function revokeAllForUser(string $userId, string $reason, ?string $exceptSessionId = null): int
    {
        $cond = ['and', ['user_id' => $userId, 'revoked_at' => null]];
        if ($exceptSessionId !== null) {
            $cond[] = ['<>', 'id', self::hash($exceptSessionId)];
        }
        return $this->db->createCommand()->update('user_sessions', ['revoked_at' => new Expression('now()'), 'revoked_reason' => $reason], $cond)->execute();
    }

    public function activeForUser(string $userId): array
    {
        return (new Query($this->db))->from('user_sessions')
            ->where(['user_id' => $userId, 'revoked_at' => null])
            ->andWhere(['>', 'expires_at', new Expression('now()')])
            ->orderBy(['last_seen_at' => SORT_DESC])->all();
    }

    public function purgeExpired(): int
    {
        return $this->db->createCommand()->delete('user_sessions', ['<', 'expires_at', (new DateTimeImmutable('-30 days'))->format(DATE_ATOM)])->execute();
    }
}
