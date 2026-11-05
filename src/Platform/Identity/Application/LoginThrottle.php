<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use App\Shared\Infrastructure\RateLimit\RedisRateLimiter;
use DateTimeImmutable;
use App\Platform\Identity\Domain\User;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Brute-force protection (ASVS V2.2):
 *  - per-IP fast limit in Redis (fail-open, cheap first line of defence),
 *  - per-account and per-IP sliding counts in PostgreSQL `login_attempts` (durable, works without Redis),
 *  - account lockout after N consecutive failures (users.locked_until).
 */
final class LoginThrottle
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RedisRateLimiter $limiter,
        private readonly int $maxAttemptsPerUser = 5,
        private readonly int $maxAttemptsPerIp = 30,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 900,
    ) {}

    /**
     * @return int|null seconds to wait when throttled, null when allowed
     */
    public function check(string $username, ?string $ip): ?int
    {
        if ($ip !== null) {
            $fast = $this->limiter->hit('login-ip:' . $ip, $this->maxAttemptsPerIp * 2, 60);
            if (!$fast->allowed) {
                return $fast->resetInSeconds;
            }
        }

        $since = (new DateTimeImmutable("-{$this->windowSeconds} seconds"))->format(DATE_ATOM);
        if ($ip !== null) {
            $ipFailures = (int) (new Query($this->db))->from('login_attempts')
                ->where(['ip' => $ip, 'success' => false])->andWhere(['>=', 'created_at', $since])->count();
            if ($ipFailures >= $this->maxAttemptsPerIp) {
                return $this->windowSeconds;
            }
        }
        return null;
    }

    /**
     * One row per sign-in attempt = the sign-in history (Account → Login history, Admin → Users → Login history).
     * $user links the row to the account (failed attempts on an existing account included).
     */
    public function record(
        string $username,
        ?string $ip,
        bool $success,
        string $reason,
        string $method = 'password',
        ?User $user = null,
        ?string $userAgent = null,
        ?string $mfaMethod = null,
    ): void {
        $this->db->createCommand()->insert('login_attempts', [
            'username' => mb_substr($username, 0, 254),
            'ip' => $ip,
            'success' => $success,
            'reason' => $reason,
            'method' => mb_substr($method, 0, 32),
            'user_id' => $user?->id,
            'tenant_id' => $user?->tenantId,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            'mfa_method' => $mfaMethod === null ? null : mb_substr($mfaMethod, 0, 32),
        ])->execute();
    }

    /**
     * Increments the consecutive-failure counter and locks the account when the threshold is reached.
     *
     * @return bool whether the account is now locked
     */
    public function registerFailure(string $userId): bool
    {
        $row = $this->db->createCommand(
            'UPDATE users SET failed_logins = failed_logins + 1,
                locked_until = CASE WHEN failed_logins + 1 >= :max THEN now() + make_interval(secs => :lock) ELSE locked_until END
             WHERE id = :id RETURNING failed_logins, locked_until',
            [':max' => $this->maxAttemptsPerUser, ':lock' => $this->lockoutSeconds, ':id' => $userId],
        )->queryOne();
        return $row !== null && $row['locked_until'] !== null && new DateTimeImmutable((string) $row['locked_until']) > new DateTimeImmutable();
    }

    public function registerSuccess(string $userId, ?string $ip): void
    {
        $this->db->createCommand()->update('users', [
            'failed_logins' => 0,
            'locked_until' => null,
            'last_login_at' => new Expression('now()'),
            'last_login_ip' => $ip,
        ], ['id' => $userId])->execute();
    }

    public function unlock(string $userId): void
    {
        $this->db->createCommand()->update('users', ['failed_logins' => 0, 'locked_until' => null], ['id' => $userId])->execute();
    }

    public function purgeOlderThan(int $days): int
    {
        return $this->db->createCommand()->delete('login_attempts', ['<', 'created_at', (new DateTimeImmutable("-$days days"))->format(DATE_ATOM)])->execute();
    }
}
