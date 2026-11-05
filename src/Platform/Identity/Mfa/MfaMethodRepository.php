<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

use App\Shared\Infrastructure\Security\Encrypter;
use SensitiveParameter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Storage of enrolled second factors (table user_mfa_methods). The delivery target (chat id, e-mail, phone,
 * certificate subject) is encrypted (purpose "mfa-target"); lookup_hash is an HMAC used for uniqueness and for
 * finding a user by an external id (Telegram chat, E-IMZO PINFL) without decrypting.
 *
 * Author: ShokirjonMK
 */
final class MfaMethodRepository
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Encrypter $encrypter,
    ) {}

    /**
     * @return list<array{id:string,type:string,label:string,target_hint:string,meta:array,created_at:string,last_used_at:?string,sign_count:int,secret_enc:?string}>
     */
    public function forUser(string $userId): array
    {
        $rows = (new Query($this->db))->from('user_mfa_methods')->where(['user_id' => $userId])->orderBy(['created_at' => SORT_ASC])->all();
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function find(string $userId, string $id): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}$/', $id) !== 1) {
            return null;
        }
        $r = (new Query($this->db))->from('user_mfa_methods')->where(['id' => $id, 'user_id' => $userId])->one();
        return $r === null ? null : $this->hydrate($r);
    }

    public function findByType(string $userId, string $type): ?array
    {
        $r = (new Query($this->db))->from('user_mfa_methods')->where(['user_id' => $userId, 'type' => $type])->orderBy(['created_at' => SORT_DESC])->one();
        return $r === null ? null : $this->hydrate($r);
    }

    /** Owner of an external id (Telegram chat id, E-IMZO PINFL) for $type, or null. */
    public function ownerOf(string $type, string $externalId): ?string
    {
        $v = (new Query($this->db))->select('user_id')->from('user_mfa_methods')
            ->where(['type' => $type, 'lookup_hash' => $this->lookup($type, $externalId)])->scalar();
        return $v === false || $v === null ? null : (string) $v;
    }

    public function target(array $method): string
    {
        return $method['target_enc'] === null ? '' : $this->encrypter->decrypt((string) $method['target_enc'], 'mfa-target');
    }

    public function secret(array $method): string
    {
        return $method['secret_enc'] === null ? '' : $this->encrypter->decrypt((string) $method['secret_enc'], 'mfa-secret');
    }

    /**
     * Creates or replaces the method of $type (one per type, except webauthn which may have several keys).
     *
     * @param array<string,mixed> $meta
     */
    public function save(string $userId, string $type, #[SensitiveParameter] string $target, string $label, array $meta = [], #[SensitiveParameter] ?string $secret = null, ?string $lookupId = null, ?string $hint = null, int $signCount = 0): string
    {
        $row = [
            'label' => mb_substr($label, 0, 120),
            'target_enc' => $target === '' ? null : $this->encrypter->encrypt($target, 'mfa-target'),
            'target_hint' => mb_substr($hint ?? MfaMethodType::mask($type, $target), 0, 120),
            'sign_count' => $signCount,
            'lookup_hash' => $this->lookup($type, $lookupId ?? $target),
            'secret_enc' => $secret === null ? null : $this->encrypter->encrypt($secret, 'mfa-secret'),
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        return $this->db->transaction(function () use ($userId, $type, $row): string {
            // the same external identity (Telegram chat, phone, certificate) cannot protect two accounts
            $this->db->createCommand()->delete('user_mfa_methods', ['type' => $type, 'lookup_hash' => $row['lookup_hash']])->execute();
            if ($type !== MfaMethodType::WEBAUTHN) {
                $this->db->createCommand()->delete('user_mfa_methods', ['user_id' => $userId, 'type' => $type])->execute();
            }
            $id = (string) $this->db->createCommand('SELECT gen_random_uuid()')->queryScalar();
            $this->db->createCommand()->insert('user_mfa_methods', ['id' => $id, 'user_id' => $userId, 'type' => $type] + $row)->execute();
            return $id;
        });
    }

    /** Is the external identity already bound to a different account? */
    public function boundToOther(string $userId, string $type, string $externalId): bool
    {
        $owner = $this->ownerOf($type, $externalId);
        return $owner !== null && $owner !== $userId;
    }

    public function touch(string $id, ?int $signCount = null): void
    {
        $data = ['last_used_at' => new Expression('now()')];
        if ($signCount !== null) {
            $data['sign_count'] = $signCount;
        }
        $this->db->createCommand()->update('user_mfa_methods', $data, ['id' => $id])->execute();
    }

    public function delete(string $userId, string $id): bool
    {
        return $this->db->createCommand()->delete('user_mfa_methods', ['id' => $id, 'user_id' => $userId])->execute() > 0;
    }

    public function deleteAll(string $userId): int
    {
        return $this->db->createCommand()->delete('user_mfa_methods', ['user_id' => $userId])->execute();
    }

    public function count(string $userId): int
    {
        return (int) (new Query($this->db))->from('user_mfa_methods')->where(['user_id' => $userId])->count();
    }

    /** @return array<string,int> method type => number of users (for the admin overview) */
    public function usage(): array
    {
        $out = [];
        foreach ((new Query($this->db))->select(['type', 'n' => new Expression('count(DISTINCT user_id)')])->from('user_mfa_methods')->groupBy('type')->all() as $r) {
            $out[(string) $r['type']] = (int) $r['n'];
        }
        $out[MfaMethodType::TOTP] = (int) (new Query($this->db))->from('users')->where(['deleted_at' => null])->andWhere(['not', ['mfa_secret_enc' => null]])->count();
        return $out;
    }

    private function lookup(string $type, string $externalId): string
    {
        return substr($this->encrypter->hmac($type . '|' . strtolower(trim($externalId)), 'mfa-lookup'), 0, 64);
    }

    private function hydrate(array $r): array
    {
        return [
            'id' => (string) $r['id'],
            'type' => (string) $r['type'],
            'label' => (string) $r['label'],
            'target_hint' => (string) $r['target_hint'],
            'target_enc' => $r['target_enc'],
            'secret_enc' => $r['secret_enc'],
            'meta' => is_array($r['meta']) ? $r['meta'] : (json_decode((string) $r['meta'], true) ?: []),
            'sign_count' => (int) $r['sign_count'],
            'created_at' => (string) $r['created_at'],
            'last_used_at' => $r['last_used_at'] === null ? null : (string) $r['last_used_at'],
        ];
    }
}
