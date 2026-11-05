<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use App\Platform\Access\AccessService;
use App\Platform\Identity\Domain\User;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Sign-in history (table login_attempts, written by LoginThrottle on every web/API sign-in attempt):
 *  - mine():  the signed-in user's own successful and failed sign-ins (also failed attempts by others on the account);
 *  - all():   every attempt, for holders of `users.login_history` (superadmin, platform/security admins, auditors);
 *             administrators without `tenants.cross` see only their tenant.
 * Retention: setting retention.login_attempts_days (Maintenance cleanup).
 *
 * Author: ShokirjonMK
 */
final class LoginHistory
{
    public const PERMISSION = 'users.login_history';
    public const SORTABLE = ['created_at' => 'a.created_at', 'username' => 'a.username', 'ip' => 'a.ip', 'success' => 'a.success'];

    /** Reason codes written by AuthenticationService → readable text (English source, translated in views). */
    public const REASONS = [
        'ok' => 'Signed in',
        'bad_password' => 'Wrong password',
        'unknown_user' => 'Unknown account',
        'bad_mfa' => 'Wrong second-factor code',
        'locked' => 'Account locked',
        'throttled' => 'Too many attempts',
        'password_not_allowed' => 'Password sign-in not allowed',
        'tenant_inactive' => 'Organisation inactive',
        'inactive_blocked' => 'Account blocked',
        'inactive_archived' => 'Account archived',
        'inactive_pending' => 'Account not activated',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
    ) {}

    /** @param array<string,string> $filters see filters() */
    public function mine(User $user, array $filters, int $page, ?string $sort = null): Page
    {
        $q = $this->base()->andWhere(['a.user_id' => $user->id]);
        $this->apply($q, $filters);
        return Paginator::paginate($q, $page, 25, $sort, self::SORTABLE, '-created_at');
    }

    /** @param array<string,string> $filters see filters() */
    public function all(User $actor, array $filters, int $page, ?string $sort = null): Page
    {
        $q = $this->scoped($actor);
        if ($filters['user'] !== '') {
            $q->andWhere(Uuid::isValid($filters['user']) ? ['a.user_id' => $filters['user']] : Sql::search($filters['user'], ['a.username', 'u.full_name', 'u.email']));
        }
        if ($filters['ip'] !== '') {
            $q->andWhere(new Expression('host(a.ip) LIKE :ip', [':ip' => $filters['ip'] . '%']));
        }
        $this->apply($q, $filters);
        return Paginator::paginate($q, $page, 50, $sort, self::SORTABLE, '-created_at');
    }

    /** @return array{success:int, failure:int, ips:int, users:int, last_success:?string} last 24 h (or for one user: 30 days) */
    public function stats(User $actor, ?string $userId = null): array
    {
        $q = $userId !== null ? $this->base()->andWhere(['a.user_id' => $userId]) : $this->scoped($actor);
        $since = date(DATE_ATOM, time() - ($userId !== null ? 30 * 86400 : 86400));
        $row = $q->select([
            'success' => 'count(*) FILTER (WHERE a.success)',
            'failure' => 'count(*) FILTER (WHERE NOT a.success)',
            'ips' => 'count(DISTINCT a.ip)',
            'users' => 'count(DISTINCT a.user_id)',
        ])->andWhere(['>=', 'a.created_at', $since])->one() ?? [];
        $last = ($userId !== null ? $this->base()->andWhere(['a.user_id' => $userId]) : $this->scoped($actor))
            ->select('max(a.created_at)')->andWhere(['a.success' => true])->scalar();
        return [
            'success' => (int) ($row['success'] ?? 0),
            'failure' => (int) ($row['failure'] ?? 0),
            'ips' => (int) ($row['ips'] ?? 0),
            'users' => (int) ($row['users'] ?? 0),
            'last_success' => is_string($last) ? $last : null,
        ];
    }

    /** @return array{user:string, ip:string, result:string, method:string, from:string, to:string} */
    public static function filters(array $query): array
    {
        $s = static fn(string $k, int $max = 100): string => mb_substr(trim(is_scalar($query[$k] ?? null) ? (string) $query[$k] : ''), 0, $max);
        $date = static fn(string $v): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : '';
        return [
            'user' => $s('user'),
            'ip' => preg_replace('/[^0-9a-fA-F:.]/', '', $s('ip', 45)) ?? '',
            'result' => in_array($s('result'), ['success', 'failure'], true) ? $s('result') : '',
            'method' => preg_match('/^[a-z0-9_:-]{1,32}$/', $s('method')) === 1 ? $s('method') : '',
            'from' => $date($s('from', 10)),
            'to' => $date($s('to', 10)),
        ];
    }

    public function mayViewAll(User $actor): bool
    {
        return $this->access->can($actor->id, self::PERMISSION);
    }

    /** "Chrome · Windows" style summary of a user agent (no third-party parser; good enough for a history list). */
    public static function device(?string $ua): string
    {
        if ($ua === null || $ua === '') {
            return '—';
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'YaBrowser') => 'Yandex',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'curl/') => 'curl',
            str_contains($ua, 'okhttp') => 'Android app',
            str_contains($ua, 'Guzzle') || str_contains($ua, 'PHP') => 'API client',
            default => 'Other',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };
        return $os === '' ? $browser : $browser . ' · ' . $os;
    }

    private function base(): Query
    {
        return (new Query($this->db))
            ->select(['a.id', 'a.created_at', 'a.username', 'a.user_id', 'a.success', 'a.reason', 'a.method', 'a.mfa_method',
                'ip' => 'host(a.ip)', 'a.user_agent', 'full_name' => 'u.full_name'])
            ->from(['a' => 'login_attempts'])
            ->leftJoin(['u' => 'users'], 'u.id = a.user_id');
    }

    private function scoped(User $actor): Query
    {
        if (!$this->mayViewAll($actor)) {
            throw new AccessDeniedException('You may only see your own sign-in history.');
        }
        $q = $this->base();
        if (!$this->access->can($actor->id, 'tenants.cross')) {
            $q->andWhere(['a.tenant_id' => $actor->tenantId]);
        }
        return $q;
    }

    private function apply(Query $q, array $f): void
    {
        if ($f['result'] !== '') {
            $q->andWhere(['a.success' => $f['result'] === 'success']);
        }
        if ($f['method'] !== '') {
            $q->andWhere(['or', ['a.method' => $f['method']], ['a.mfa_method' => $f['method']]]);
        }
        if ($f['from'] !== '') {
            $q->andWhere(['>=', 'a.created_at', $f['from']]);
        }
        if ($f['to'] !== '') {
            $q->andWhere(['<', 'a.created_at', date('Y-m-d', (int) strtotime($f['to'] . ' +1 day'))]);
        }
    }
}
