<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Context\RequestContext;
use DateTimeImmutable;
use SensitiveParameter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Opaque API tokens (random 256-bit, stored as SHA-256), with refresh-token rotation and
 * reuse detection: presenting an already-rotated refresh token revokes the whole token family.
 *
 * Token format: "yep_" + type letter + "_" + 43 base64url chars (prefix enables secret scanning).
 */
final class ApiTokenService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly \App\Platform\Identity\Infrastructure\UserRepository $users,
        private readonly RequestContext $context,
        private readonly int $accessTtl = 3600,
        private readonly int $refreshTtl = 2592000,
    ) {}

    /**
     * @param list<string> $scopes
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, scope:string}
     */
    public function issuePair(User $user, array $scopes = ['*'], ?string $familyId = null): array
    {
        $familyId ??= \App\Shared\Domain\Uuid::v7();
        $access = $this->insert($user->id, 'access', $scopes, $this->accessTtl, $familyId, 'access');
        $refresh = $this->insert($user->id, 'refresh', $scopes, $this->refreshTtl, $familyId, 'refresh');
        return [
            'access_token' => $access['token'],
            'refresh_token' => $refresh['token'],
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTtl,
            'scope' => implode(' ', $scopes),
        ];
    }

    /**
     * @param list<string> $scopes
     * @return array{id:string, token:string}
     */
    public function issuePersonal(string $userId, string $name, array $scopes, int $ttlDays): array
    {
        if ($ttlDays < 1 || $ttlDays > 366) {
            throw ValidationException::single('ttl_days', 'Token lifetime must be between 1 and 366 days.');
        }
        return $this->insert($userId, 'personal', $scopes, $ttlDays * 86400, \App\Shared\Domain\Uuid::v7(), $name);
    }

    public function authenticate(#[SensitiveParameter] string $token): ?Identity
    {
        if (!preg_match('/^yep_[ap]_[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $row = (new Query($this->db))->from('api_tokens')
            ->where(['token_hash' => hash('sha256', $token), 'revoked_at' => null])
            ->andWhere(['in', 'type', ['access', 'personal']])
            ->andWhere(['>', 'expires_at', new Expression('now()')])
            ->one();
        if ($row === null) {
            return null;
        }
        $user = $this->users->find((string) $row['user_id']);
        if ($user === null || !$user->isActive()) {
            return null;
        }
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < time() - 60) {
            $this->db->createCommand()->update('api_tokens', ['last_used_at' => new Expression('now()'), 'last_used_ip' => $this->context->ip()], ['id' => $row['id']])->execute();
        }
        $scopes = json_decode((string) $row['scopes'], true) ?: [];
        return new Identity($user, 'token', $scopes, (string) $row['id']);
    }

    /**
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, scope:string}|null
     */
    public function refresh(#[SensitiveParameter] string $refreshToken): ?array
    {
        if (!preg_match('/^yep_r_[A-Za-z0-9_-]{43}$/', $refreshToken)) {
            return null;
        }
        return $this->db->transaction(function () use ($refreshToken): ?array {
            $row = $this->db->createCommand(
                'SELECT * FROM api_tokens WHERE token_hash = :h AND type = \'refresh\' FOR UPDATE',
                [':h' => hash('sha256', $refreshToken)],
            )->queryOne();
            if ($row === null) {
                return null;
            }
            if ($row['revoked_at'] !== null) {
                // Reuse of a rotated refresh token => token theft suspected: kill the whole family.
                $this->revokeFamily((string) $row['family_id'], 'refresh_reuse_detected');
                return null;
            }
            if (new DateTimeImmutable((string) $row['expires_at']) < new DateTimeImmutable()) {
                return null;
            }
            $user = $this->users->find((string) $row['user_id']);
            if ($user === null || !$user->isActive()) {
                return null;
            }
            $this->db->createCommand()->update('api_tokens', ['revoked_at' => new Expression('now()')], ['family_id' => $row['family_id'], 'revoked_at' => null])->execute();
            $scopes = json_decode((string) $row['scopes'], true) ?: [];
            return $this->issuePair($user, $scopes, (string) $row['family_id']);
        });
    }

    public function revokeByToken(#[SensitiveParameter] string $token): void
    {
        $row = (new Query($this->db))->from('api_tokens')->where(['token_hash' => hash('sha256', $token)])->one();
        if ($row !== null) {
            $this->revokeFamily((string) $row['family_id'], 'logout');
        }
    }

    public function revoke(string $tokenId, ?string $userId = null): bool
    {
        $cond = ['id' => $tokenId, 'revoked_at' => null];
        if ($userId !== null) {
            $cond['user_id'] = $userId;
        }
        return $this->db->createCommand()->update('api_tokens', ['revoked_at' => new Expression('now()')], $cond)->execute() > 0;
    }

    public function revokeAllForUser(string $userId): int
    {
        return $this->db->createCommand()->update('api_tokens', ['revoked_at' => new Expression('now()')], ['user_id' => $userId, 'revoked_at' => null])->execute();
    }

    public function revokeFamily(string $familyId, string $reason): void
    {
        $this->db->createCommand()->update('api_tokens', ['revoked_at' => new Expression('now()')], ['family_id' => $familyId, 'revoked_at' => null])->execute();
    }

    public function listForUser(string $userId): array
    {
        return (new Query($this->db))->select(['id', 'name', 'type', 'scopes', 'expires_at', 'last_used_at', 'last_used_ip', 'revoked_at', 'created_at'])
            ->from('api_tokens')->where(['user_id' => $userId, 'type' => 'personal'])->orderBy(['created_at' => SORT_DESC])->all();
    }

    /**
     * @param list<string> $scopes
     * @return array{id:string, token:string}
     */
    private function insert(string $userId, string $type, array $scopes, int $ttl, string $familyId, string $name): array
    {
        $letter = match ($type) {
            'refresh' => 'r', 'personal' => 'p', default => 'a'
        };
        $token = 'yep_' . $letter . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = \App\Shared\Domain\Uuid::v7();
        $this->db->createCommand()->insert('api_tokens', [
            'id' => $id,
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 128),
            'type' => $type,
            'token_hash' => hash('sha256', $token),
            'family_id' => $familyId,
            'scopes' => json_encode(array_values($scopes)),
            'expires_at' => (new DateTimeImmutable("+$ttl seconds"))->format(DATE_ATOM),
        ])->execute();
        return ['id' => $id, 'token' => $token];
    }
}
