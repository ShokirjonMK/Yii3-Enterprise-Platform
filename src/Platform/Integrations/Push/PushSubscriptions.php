<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Push;

use App\Platform\Integrations\Exception\SsrfBlockedException;
use App\Platform\Security\UrlGuard;
use App\Shared\Domain\Exception\ValidationException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Web Push subscriptions (browser PushSubscription.toJSON()) per user. The endpoint is chosen by the browser —
 * i.e. it is user input — so it must be HTTPS and pass the SSRF guard both at subscribe and at send time.
 */
final class PushSubscriptions
{
    public const MAX_PER_USER = 20;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UrlGuard $guard,
    ) {}

    /**
     * @param array{endpoint?:mixed, keys?:array{p256dh?:mixed, auth?:mixed}, contentEncoding?:mixed} $sub
     * @return string subscription id
     */
    public function save(string $userId, array $sub, ?string $userAgent = null): string
    {
        $endpoint = trim((string) ($sub['endpoint'] ?? ''));
        $p256dh = trim((string) ($sub['keys']['p256dh'] ?? ''));
        $auth = trim((string) ($sub['keys']['auth'] ?? ''));
        $enc = (string) ($sub['contentEncoding'] ?? 'aes128gcm');
        $e = [];
        if ($endpoint === '' || strlen($endpoint) > 2048 || !str_starts_with($endpoint, 'https://')) {
            $e['endpoint'][] = 'An HTTPS push endpoint is required.';
        } else {
            try {
                $this->guard->assertAllowed($endpoint);
            } catch (SsrfBlockedException $ex) {
                $e['endpoint'][] = 'Push endpoint is not allowed: ' . $ex->getMessage();
            }
        }
        // P-256 public key: 65 bytes uncompressed, auth secret: 16 bytes (base64url)
        if (strlen(self::b64urlDecode($p256dh)) !== 65) {
            $e['keys.p256dh'][] = 'Invalid p256dh key.';
        }
        if (strlen(self::b64urlDecode($auth)) !== 16) {
            $e['keys.auth'][] = 'Invalid auth secret.';
        }
        if (!in_array($enc, ['aes128gcm', 'aesgcm'], true)) {
            $e['contentEncoding'][] = 'Unsupported content encoding.';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $count = (int) (new Query($this->db))->from('push_subscriptions')->where(['user_id' => $userId])->count();
        if ($count >= self::MAX_PER_USER) {
            // keep the newest MAX-1 subscriptions
            $this->db->createCommand(
                'DELETE FROM push_subscriptions WHERE id IN (SELECT id FROM push_subscriptions WHERE user_id = :u ORDER BY created_at ASC LIMIT :n)',
                [':u' => $userId, ':n' => $count - self::MAX_PER_USER + 1],
            )->execute();
        }
        return (string) $this->db->createCommand(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, content_encoding, user_agent)
             VALUES (:u, :e, :h, :p, :a, :c, :ua)
             ON CONFLICT (endpoint_hash) DO UPDATE SET user_id = EXCLUDED.user_id, p256dh = EXCLUDED.p256dh, auth = EXCLUDED.auth,
                 content_encoding = EXCLUDED.content_encoding, user_agent = EXCLUDED.user_agent, last_error = NULL
             RETURNING id',
            [':u' => $userId, ':e' => $endpoint, ':h' => hash('sha256', $endpoint), ':p' => $p256dh, ':a' => $auth, ':c' => $enc, ':ua' => $userAgent === null ? null : mb_substr($userAgent, 0, 500)],
        )->queryScalar();
    }

    public function delete(string $userId, string $endpoint): int
    {
        return $this->db->createCommand()->delete('push_subscriptions', ['user_id' => $userId, 'endpoint_hash' => hash('sha256', trim($endpoint))])->execute();
    }

    /** @return list<array<string,mixed>> */
    public function forUser(string $userId): array
    {
        return (new Query($this->db))->from('push_subscriptions')->where(['user_id' => $userId])->orderBy(['created_at' => SORT_DESC])->all();
    }

    public function count(): int
    {
        return (int) (new Query($this->db))->from('push_subscriptions')->count();
    }

    public function markUsed(string $id): void
    {
        $this->db->createCommand()->update('push_subscriptions', ['last_used_at' => new Expression('now()'), 'last_error' => null], ['id' => $id])->execute();
    }

    public function markFailed(string $id, string $error): void
    {
        $this->db->createCommand()->update('push_subscriptions', ['last_error' => mb_substr($error, 0, 1000)], ['id' => $id])->execute();
    }

    public function remove(string $id): void
    {
        $this->db->createCommand()->delete('push_subscriptions', ['id' => $id])->execute();
    }

    public static function b64urlDecode(string $v): string
    {
        $d = base64_decode(strtr($v, '-_', '+/') . str_repeat('=', (4 - strlen($v) % 4) % 4), true);
        return $d === false ? '' : $d;
    }
}
