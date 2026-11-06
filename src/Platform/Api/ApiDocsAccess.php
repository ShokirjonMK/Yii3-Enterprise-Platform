<?php

declare(strict_types=1);

namespace App\Platform\Api;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\TooManyRequestsException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\RateLimit\RedisRateLimiter;
use DateTimeImmutable;
use SensitiveParameter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/**
 * Who may read the API documentation (Swagger UI /api/docs and /api/v1/openapi.json):
 *  - a signed-in user whose roles include api.docs.view (granted in Admin → RBAC like any permission), or
 *  - anybody who enters an access password issued by a superadmin (api.docs.manage) in
 *    Admin → API Management → Docs access. The password is shown once, stored as a SHA-256 hash, expires,
 *    can be revoked, and unlocks the docs for the current browser session only (at most SESSION_HOURS).
 * Unlock attempts are rate-limited per IP and audited.
 */
final class ApiDocsAccess
{
    public const PERMISSION = 'api.docs.view';
    public const MANAGE = 'api.docs.manage';
    public const SESSION_KEY = 'api_docs_grant';
    public const SESSION_HOURS = 12;
    public const MAX_DAYS = 365;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly RedisRateLimiter $limiter,
    ) {}

    /** Can the current request read the docs? Returns how: 'permission', 'password' or null. */
    public function grant(CurrentUser $user, SessionInterface $session): ?string
    {
        if (!$user->isGuest() && $this->access->can((string) $user->getId(), self::PERMISSION)) {
            return 'permission';
        }
        $g = $session->get(self::SESSION_KEY);
        if (!is_array($g) || !isset($g['id'], $g['until']) || (int) $g['until'] < time()) {
            return null;
        }
        $active = (new Query($this->db))->from('api_docs_passwords')
            ->where(['id' => (string) $g['id'], 'revoked_at' => null])->andWhere('expires_at > now()')->exists();
        if (!$active) {
            $session->remove(self::SESSION_KEY);
            return null;
        }
        return 'password';
    }

    /** Checks an access password; on success the browser session is unlocked. */
    public function unlock(SessionInterface $session, #[SensitiveParameter] string $password, string $ip): void
    {
        $rate = $this->limiter->hit('api-docs-unlock:' . $ip, 10, 900, failOpen: false);
        if (!$rate->allowed) {
            throw new TooManyRequestsException('Too many attempts. Try again later.', $rate->resetInSeconds);
        }
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $password) ?? '');
        $row = $normalized === '' ? false : (new Query($this->db))->select(['id', 'label'])->from('api_docs_passwords')
            ->where(['secret_hash' => hash('sha256', $normalized), 'revoked_at' => null])->andWhere('expires_at > now()')->one();
        if (!$row) {
            $this->audit->record('api.docs.unlock_failed', 'api_docs', null, metadata: ['ip' => $ip], result: 'failure', category: 'security', severity: 'warning');
            throw ValidationException::single('password', 'The password is wrong, expired or revoked.');
        }
        $this->db->createCommand('UPDATE api_docs_passwords SET last_used_at = now(), use_count = use_count + 1 WHERE id = :id', [':id' => $row['id']])->execute();
        $this->limiter->reset('api-docs-unlock:' . $ip);
        $session->regenerateId();
        $session->set(self::SESSION_KEY, ['id' => (string) $row['id'], 'until' => time() + self::SESSION_HOURS * 3600]);
        $this->audit->record('api.docs.unlocked', 'api_docs', (string) $row['id'], metadata: ['label' => $row['label'], 'ip' => $ip], category: 'security', severity: 'notice');
    }

    public function lock(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    /**
     * Issues a new access password (shown once).
     *
     * @return array{id: string, password: string, expires_at: string}
     */
    public function issue(string $actorId, string $label, int $days): array
    {
        $this->access->assert($actorId, self::MANAGE);
        $label = trim($label);
        $errors = [];
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'][] = 'Enter who the password is for (1–120 characters).';
        }
        if ($days < 1 || $days > self::MAX_DAYS) {
            $errors['days'][] = 'Validity must be between 1 and ' . self::MAX_DAYS . ' days.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $raw = '';
        for ($i = 0; $i < 20; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $expires = (new DateTimeImmutable())->modify("+$days days");
        $id = (string) $this->db->createCommand(
            'INSERT INTO api_docs_passwords (label, secret_hash, hint, expires_at, created_by) VALUES (:l, :h, :hint, :e, :u) RETURNING id',
            [':l' => $label, ':h' => hash('sha256', $raw), ':hint' => substr($raw, -4), ':e' => $expires->format('Y-m-d H:i:sP'), ':u' => $actorId],
        )->queryScalar();
        $this->audit->record('api.docs.password_issued', 'api_docs', $id, after: ['label' => $label, 'expires_at' => $expires->format(DATE_ATOM)], category: 'security', severity: 'notice');
        return ['id' => $id, 'password' => implode('-', str_split($raw, 5)), 'expires_at' => $expires->format(DATE_ATOM)];
    }

    public function revoke(string $actorId, string $id): void
    {
        $this->access->assert($actorId, self::MANAGE);
        $n = $this->db->createCommand(
            'UPDATE api_docs_passwords SET revoked_at = now(), revoked_by = :u WHERE id = :id AND revoked_at IS NULL',
            [':u' => $actorId, ':id' => $id],
        )->execute();
        if ($n === 0) {
            throw new NotFoundException('Password not found or already revoked.');
        }
        $this->audit->record('api.docs.password_revoked', 'api_docs', $id, category: 'security', severity: 'notice');
    }

    /** @return list<array<string, mixed>> newest first, with a computed status (active|expired|revoked) */
    public function list(int $limit = 200): array
    {
        $rows = (new Query($this->db))
            ->select(['p.*', 'creator' => 'u.username', 'active' => new \Yiisoft\Db\Expression\Expression('(p.revoked_at IS NULL AND p.expires_at > now())')])
            ->from(['p' => 'api_docs_passwords'])->leftJoin(['u' => 'users'], 'u.id = p.created_by')
            ->orderBy(['p.created_at' => SORT_DESC])->limit($limit)->all();
        foreach ($rows as &$r) {
            $r['status'] = $r['revoked_at'] !== null ? 'revoked' : ($r['active'] ? 'active' : 'expired');
        }
        return $rows;
    }
}
