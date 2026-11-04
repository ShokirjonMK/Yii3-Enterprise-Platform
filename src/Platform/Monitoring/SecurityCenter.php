<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Application\ApiTokenService;
use App\Platform\Identity\Application\LoginThrottle;
use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Domain\User;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * 18 · Security center data and operations: security events, sign-in failures, active sessions
 * across users (revoke), account unlock, revoke all API tokens of a user.
 *
 * Tenant isolation: actors without tenants.cross only see / act on users of their own tenant.
 */
final class SecurityCenter
{
    public const SESSION_SORT = ['last_seen' => 's.last_seen_at', 'created' => 's.created_at', 'user' => 'u.username', 'ip' => 's.ip'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly SessionRegistry $sessions,
        private readonly ApiTokenService $tokens,
        private readonly LoginThrottle $throttle,
    ) {}

    /** @return list<array<string,mixed>> */
    public function recentEvents(User $actor, int $limit = 25): array
    {
        $q = (new Query($this->db))->select(['id', 'occurred_at', 'action', 'result', 'severity', 'actor_name', 'actor_id', 'ip', 'metadata'])
            ->from('audit_log')->where(['category' => 'security'])
            ->andWhere(['>', 'occurred_at', new Expression("now() - interval '30 days'")])
            ->orderBy(['occurred_at' => SORT_DESC])->limit($limit);
        $tenant = $this->tenant($actor);
        if ($tenant !== null) {
            $q->andWhere(['tenant_id' => $tenant]);
        }
        return $q->all();
    }

    /** @return list<array{ip:?string, failures:int, users:int, last:string}> */
    public function failuresByIp(int $hours = 24, int $limit = 15): array
    {
        return $this->db->createCommand("SELECT host(ip) AS ip, count(*) AS failures, count(DISTINCT lower(username)) AS users, max(created_at) AS last
            FROM login_attempts WHERE NOT success AND created_at > now() - make_interval(hours => :h)
            GROUP BY ip ORDER BY failures DESC LIMIT :l", [':h' => $hours, ':l' => $limit])->queryAll();
    }

    /** @return list<array<string,mixed>> */
    public function failuresByUser(User $actor, int $hours = 24, int $limit = 15): array
    {
        $tenant = $this->tenant($actor);
        $sql = "SELECT lower(a.username) AS username, count(*) AS failures, count(DISTINCT a.ip) AS ips, max(a.created_at) AS last,
                       u.id AS user_id, u.locked_until, (u.locked_until > now()) AS locked
                FROM login_attempts a
                LEFT JOIN users u ON (lower(u.username) = lower(a.username) OR lower(u.email) = lower(a.username)) AND u.deleted_at IS NULL
                WHERE NOT a.success AND a.created_at > now() - make_interval(hours => :h)"
            . ($tenant !== null ? ' AND u.tenant_id = :t' : '') . "
                GROUP BY lower(a.username), u.id, u.locked_until ORDER BY failures DESC LIMIT :l";
        $params = [':h' => $hours, ':l' => $limit];
        if ($tenant !== null) {
            $params[':t'] = $tenant;
        }
        return $this->db->createCommand($sql, $params)->queryAll();
    }

    /** @return list<array<string,mixed>> */
    public function lockedUsers(User $actor): array
    {
        $q = (new Query($this->db))->select(['id', 'username', 'full_name', 'failed_logins', 'locked_until', 'last_login_at'])->from('users')
            ->where(['deleted_at' => null])->andWhere(['>', 'locked_until', new Expression('now()')])->orderBy(['locked_until' => SORT_DESC])->limit(50);
        $tenant = $this->tenant($actor);
        if ($tenant !== null) {
            $q->andWhere(['tenant_id' => $tenant]);
        }
        return $q->all();
    }

    public function activeSessions(User $actor, array $filters, int $page, ?string $sort): Page
    {
        $q = (new Query($this->db))
            ->select(['s.id', 's.user_id', 's.auth_method', 'ip' => new Expression('host(s.ip)'), 's.user_agent', 's.created_at', 's.last_seen_at', 's.expires_at', 's.mfa_verified_at', 'u.username', 'u.full_name'])
            ->from(['s' => 'user_sessions'])->innerJoin(['u' => 'users'], 'u.id = s.user_id')
            ->where(['s.revoked_at' => null])->andWhere(['>', 's.expires_at', new Expression('now()')]);
        $tenant = $this->tenant($actor);
        if ($tenant !== null) {
            $q->andWhere(['u.tenant_id' => $tenant]);
        }
        if (($filters['q'] ?? '') !== '') {
            $q->andWhere(\App\Platform\Infrastructure\OpsSql::contains((string) $filters['q'], ['u.username', 'u.full_name', 's.ip']));
        }
        return Paginator::paginate($q, $page, 25, $sort, self::SESSION_SORT, '-last_seen');
    }

    public function revokeSession(User $actor, string $hashedId, ?string $currentSessionHash): void
    {
        $this->access->assert($actor->id, 'sessions.revoke');
        if (preg_match('/^[0-9a-f]{64}$/', $hashedId) !== 1) {
            throw new NotFoundException('Session not found.');
        }
        $row = (new Query($this->db))->select(['s.user_id', 'u.username', 'u.tenant_id'])->from(['s' => 'user_sessions'])->innerJoin(['u' => 'users'], 'u.id = s.user_id')
            ->where(['s.id' => $hashedId, 's.revoked_at' => null])->one();
        if ($row === null || !$this->inScope($actor, $row['tenant_id'])) {
            throw new NotFoundException('Session not found.');
        }
        if ($currentSessionHash !== null && hash_equals($currentSessionHash, $hashedId)) {
            throw new \App\Shared\Domain\Exception\ConflictException('Use "Sign out" to end your own current session.');
        }
        $this->sessions->revoke($hashedId, 'revoked by ' . $actor->username);
        $this->audit->record('security.session_revoked', 'user', (string) $row['user_id'], null, null, ['target' => $row['username']],
            category: 'security', severity: 'notice', actorId: $actor->id, actorName: $actor->username);
    }

    public function revokeAllSessions(User $actor, string $userId, ?string $keepSessionId): int
    {
        $this->access->assert($actor->id, 'sessions.revoke');
        $user = $this->user($actor, $userId);
        $n = $this->sessions->revokeAllForUser($user['id'], 'revoked by ' . $actor->username, $user['id'] === $actor->id ? $keepSessionId : null);
        $this->audit->record('security.sessions_revoked_all', 'user', $user['id'], null, null, ['target' => $user['username'], 'count' => $n],
            category: 'security', severity: 'warning', actorId: $actor->id, actorName: $actor->username);
        return $n;
    }

    public function revokeApiTokens(User $actor, string $userId): int
    {
        $this->access->assert($actor->id, 'security.manage');
        $user = $this->user($actor, $userId);
        $n = $this->tokens->revokeAllForUser($user['id']);
        $this->audit->record('security.api_tokens_revoked', 'user', $user['id'], null, null, ['target' => $user['username'], 'count' => $n],
            category: 'security', severity: 'warning', actorId: $actor->id, actorName: $actor->username);
        return $n;
    }

    public function unlock(User $actor, string $userId): void
    {
        $this->access->assert($actor->id, 'security.manage');
        $user = $this->user($actor, $userId);
        $this->throttle->unlock($user['id']);
        $this->audit->record('security.user_unlocked', 'user', $user['id'], ['locked_until' => $user['locked_until']], ['locked_until' => null], ['target' => $user['username']],
            category: 'security', severity: 'notice', actorId: $actor->id, actorName: $actor->username);
    }

    /** @return array{id:string, username:string, tenant_id:?string, locked_until:?string} */
    private function user(User $actor, string $userId): array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $userId) !== 1) {
            throw new NotFoundException('User not found.');
        }
        $u = (new Query($this->db))->select(['id', 'username', 'tenant_id', 'locked_until'])->from('users')->where(['id' => $userId, 'deleted_at' => null])->one();
        if ($u === null || !$this->inScope($actor, $u['tenant_id'])) {
            throw new NotFoundException('User not found.');
        }
        return $u;
    }

    private function inScope(User $actor, mixed $tenantId): bool
    {
        $t = $this->tenant($actor);
        return $t === null || $t === $tenantId;
    }

    private function tenant(User $actor): ?string
    {
        return $this->access->can($actor->id, 'tenants.cross') ? null : $actor->tenantId;
    }
}
