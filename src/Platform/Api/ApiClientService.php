<?php

declare(strict_types=1);

namespace App\Platform\Api;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\User;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Database\Page;
use App\Shared\Infrastructure\Database\Paginator;
use App\Shared\Infrastructure\Database\Sql;
use App\Platform\Tenancy\TextSearch;
use App\Shared\Infrastructure\Http\ClientIp;
use DateTimeImmutable;
use SensitiveParameter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Machine-to-machine API clients (OAuth2 client-credentials style) and the cross-user view of personal tokens.
 *
 * Security:
 *  - client secrets are random 256-bit values ("yep_s_" + 43 base64url chars) returned exactly once
 *    (on create / rotate); only an Argon2id hash is stored (`api_clients.secret_hash`);
 *  - {@see authenticate()} verifies the secret with password_verify (constant-time, dummy hash for unknown
 *    clients), the enabled flag, expiry, tenant status and the CIDR allowlist;
 *  - api.view / api.manage permissions; tenant isolation for actors without tenants.cross.
 */
final class ApiClientService
{
    public const CLIENT_ID_PATTERN = '/^yepc_[a-f0-9]{24}$/';
    private const SECRET_PATTERN = '/^yep_s_[A-Za-z0-9_-]{43}$/';

    public const SORTABLE = [
        'name' => 'c.name',
        'client_id' => 'c.client_id',
        'created_at' => 'c.created_at',
        'last_used_at' => 'c.last_used_at',
        'expires_at' => 'c.expires_at',
    ];

    public const TOKEN_SORTABLE = [
        'created_at' => 'k.created_at',
        'last_used_at' => 'k.last_used_at',
        'expires_at' => 'k.expires_at',
        'username' => 'u.username',
        'name' => 'k.name',
    ];

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ client-credentials authentication

    /**
     * Verifies client credentials for the REST API (client-credentials grant).
     * Returns the public client record or null (never says why — callers must answer with a generic 401).
     *
     * @return array{id:string, client_id:string, name:string, tenant_id:?string, service_user_id:?string, scopes:list<string>, rate_limit_per_minute:int}|null
     */
    public function authenticate(string $clientId, #[SensitiveParameter] string $secret, ?string $ip): ?array
    {
        $row = null;
        if (preg_match(self::CLIENT_ID_PATTERN, $clientId) && strlen($secret) <= 256) {
            $row = (new Query($this->db))
                ->select(['c.*', 'tenant_status' => 't.status'])
                ->from(['c' => 'api_clients'])
                ->leftJoin(['t' => 'tenants'], 't.id = c.tenant_id')
                ->where(['c.client_id' => $clientId])
                ->one();
        }
        if ($row === null) {
            password_verify($secret, self::dummyHash()); // equalize timing for unknown client ids
            return null;
        }
        if (!preg_match(self::SECRET_PATTERN, $secret) || !password_verify($secret, (string) $row['secret_hash'])) {
            return null;
        }
        if (!Sql::bool($row['enabled'])) {
            return null;
        }
        if ($row['expires_at'] !== null && new DateTimeImmutable((string) $row['expires_at']) <= new DateTimeImmutable()) {
            return null;
        }
        if ($row['tenant_id'] !== null && $row['tenant_status'] !== 'active') {
            return null;
        }
        if (!self::ipAllowed($ip, Sql::json($row['allowed_ips']))) {
            return null;
        }

        $update = [];
        if (password_needs_rehash((string) $row['secret_hash'], PASSWORD_ARGON2ID)) {
            $update['secret_hash'] = password_hash($secret, PASSWORD_ARGON2ID);
        }
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < time() - 60) {
            $update['last_used_at'] = new Expression('now()');
        }
        if ($update !== []) {
            $this->db->createCommand()->update('api_clients', $update, ['id' => $row['id']])->execute();
        }
        return [
            'id' => (string) $row['id'],
            'client_id' => (string) $row['client_id'],
            'name' => (string) $row['name'],
            'tenant_id' => $row['tenant_id'],
            'service_user_id' => $row['service_user_id'],
            'scopes' => array_values(Sql::json($row['scopes'])),
            'rate_limit_per_minute' => (int) $row['rate_limit_per_minute'],
        ];
    }

    /** @param list<string> $allowlist empty = any address */
    public static function ipAllowed(?string $ip, array $allowlist): bool
    {
        if ($allowlist === []) {
            return true;
        }
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach ($allowlist as $cidr) {
            if (ClientIp::inCidr($ip, (string) $cidr)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ administration

    public function tenantRestriction(User $actor): ?string
    {
        return $this->access->can($actor->id, 'tenants.cross') ? null : ($actor->tenantId ?? '00000000-0000-0000-0000-000000000000');
    }

    /** @param array{q?:string,status?:string} $filters status: enabled|disabled|expired */
    public function list(User $actor, array $filters, int $page, int $perPage, ?string $sort): Page
    {
        $this->access->assert($actor->id, 'api.view');
        $q = (new Query($this->db))
            ->select(['c.id', 'c.name', 'c.client_id', 'c.scopes', 'c.allowed_ips', 'c.rate_limit_per_minute', 'c.enabled',
                'c.expires_at', 'c.last_used_at', 'c.created_at', 'c.tenant_id', 't.name AS tenant_name', 'u.username AS service_username'])
            ->from(['c' => 'api_clients'])
            ->leftJoin(['t' => 'tenants'], 't.id = c.tenant_id')
            ->leftJoin(['u' => 'users'], 'u.id = c.service_user_id');
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            $q->andWhere(['c.tenant_id' => $restriction]);
        }
        if (($filters['q'] ?? '') !== '') {
            $q->andWhere(TextSearch::condition((string) $filters['q'], ['c.name', 'c.client_id']));
        }
        match ($filters['status'] ?? '') {
            'enabled' => $q->andWhere(['c.enabled' => true])->andWhere(['or', ['c.expires_at' => null], ['>', 'c.expires_at', new Expression('now()')]]),
            'disabled' => $q->andWhere(['c.enabled' => false]),
            'expired' => $q->andWhere(['<=', 'c.expires_at', new Expression('now()')]),
            default => null,
        };
        return Paginator::paginate($q, $page, $perPage, $sort, self::SORTABLE, 'name')
            ->map(static fn(array $r) => self::hydrate($r));
    }

    public function get(User $actor, string $id): array
    {
        $this->access->assert($actor->id, 'api.view');
        return $this->load($actor, $id);
    }

    /**
     * @return array{client:array, secret:string}  the plain secret is returned ONCE and never stored
     */
    public function create(User $actor, array $input): array
    {
        $this->access->assert($actor->id, 'api.manage');
        $data = $this->validate($actor, $input, true);
        $secret = self::generateSecret();
        $data['id'] = Uuid::v7();
        $data['client_id'] = 'yepc_' . bin2hex(random_bytes(12));
        $data['secret_hash'] = password_hash($secret, PASSWORD_ARGON2ID);
        $data['enabled'] = true;

        $client = $this->db->transaction(function () use ($data): array {
            $this->db->createCommand()->insert('api_clients', Sql::row($data))->execute();
            $client = $this->find($data['id']);
            $this->audit->record('api.client.created', 'api_client', $data['id'], null, self::publicRow($client), category: 'security', severity: 'notice');
            return $client;
        });
        return ['client' => $client, 'secret' => $secret];
    }

    public function update(User $actor, string $id, array $input): array
    {
        $this->access->assert($actor->id, 'api.manage');
        $before = $this->load($actor, $id);
        $data = $this->validate($actor, $input + ['tenant_id' => $before['tenant_id']], false);
        unset($data['tenant_id']); // the owning tenant of a client is immutable
        return $this->db->transaction(function () use ($id, $before, $data): array {
            $this->db->createCommand()->update('api_clients', Sql::row($data), ['id' => $id])->execute();
            $after = $this->find($id);
            $this->audit->record('api.client.updated', 'api_client', $id, self::publicRow($before), self::publicRow($after), category: 'security', severity: 'notice');
            return $after;
        });
    }

    public function setEnabled(User $actor, string $id, bool $enabled): void
    {
        $this->access->assert($actor->id, 'api.manage');
        $client = $this->load($actor, $id);
        $this->db->transaction(function () use ($client, $enabled): void {
            $this->db->createCommand()->update('api_clients', ['enabled' => $enabled], ['id' => $client['id']])->execute();
            $this->audit->record(
                $enabled ? 'api.client.enabled' : 'api.client.disabled',
                'api_client',
                (string) $client['id'],
                ['enabled' => $client['enabled']],
                ['enabled' => $enabled],
                category: 'security',
                severity: 'notice',
            );
        });
    }

    /** @return string the new plain secret (shown once). The previous secret stops working immediately. */
    public function rotateSecret(User $actor, string $id): string
    {
        $this->access->assert($actor->id, 'api.manage');
        $client = $this->load($actor, $id);
        $secret = self::generateSecret();
        $hash = password_hash($secret, PASSWORD_ARGON2ID);
        $this->db->transaction(function () use ($client, $hash): void {
            $this->db->createCommand()->update('api_clients', ['secret_hash' => $hash], ['id' => $client['id']])->execute();
            $this->audit->record(
                'api.client.secret_rotated',
                'api_client',
                (string) $client['id'],
                null,
                null,
                ['client_id' => $client['client_id']],
                category: 'security',
                severity: 'warning',
            );
        });
        return $secret;
    }

    public function delete(User $actor, string $id): void
    {
        $this->access->assert($actor->id, 'api.manage');
        $client = $this->load($actor, $id);
        $this->db->transaction(function () use ($client): void {
            $this->db->createCommand()->delete('api_clients', ['id' => $client['id']])->execute();
            $this->audit->record('api.client.deleted', 'api_client', (string) $client['id'], self::publicRow($client), null, category: 'security', severity: 'warning');
        });
    }

    public function load(User $actor, string $id): array
    {
        $row = Uuid::isValid($id) ? $this->find($id) : null;
        $restriction = $this->tenantRestriction($actor);
        if ($row === null || ($restriction !== null && $row['tenant_id'] !== $restriction)) {
            throw new NotFoundException('API client not found.');
        }
        return $row;
    }

    // ------------------------------------------------------------------ personal tokens (all users)

    /** @param array{q?:string,status?:string} $filters status: active|revoked|expired */
    public function personalTokens(User $actor, array $filters, int $page, int $perPage, ?string $sort): Page
    {
        $this->access->assert($actor->id, 'api.view');
        $q = (new Query($this->db))
            ->select(['k.id', 'k.name', 'k.scopes', 'k.expires_at', 'k.last_used_at', 'k.last_used_ip', 'k.revoked_at', 'k.created_at',
                'k.user_id', 'u.username', 'u.full_name', 't.name AS tenant_name'])
            ->from(['k' => 'api_tokens'])
            ->innerJoin(['u' => 'users'], 'u.id = k.user_id')
            ->leftJoin(['t' => 'tenants'], 't.id = u.tenant_id')
            ->where(['k.type' => 'personal']);
        $restriction = $this->tenantRestriction($actor);
        if ($restriction !== null) {
            $q->andWhere(['u.tenant_id' => $restriction]);
        }
        if (($filters['q'] ?? '') !== '') {
            $q->andWhere(TextSearch::condition((string) $filters['q'], ['k.name', 'u.username', 'u.full_name']));
        }
        match ($filters['status'] ?? '') {
            'active' => $q->andWhere(['k.revoked_at' => null])->andWhere(['>', 'k.expires_at', new Expression('now()')]),
            'revoked' => $q->andWhere(['not', ['k.revoked_at' => null]]),
            'expired' => $q->andWhere(['k.revoked_at' => null])->andWhere(['<=', 'k.expires_at', new Expression('now()')]),
            default => null,
        };
        return Paginator::paginate($q, $page, $perPage, $sort, self::TOKEN_SORTABLE, '-created_at')
            ->map(static function (array $r): array {
                $r['scopes'] = Sql::json($r['scopes']);
                $r['state'] = $r['revoked_at'] !== null ? 'revoked' : (strtotime((string) $r['expires_at']) <= time() ? 'expired' : 'active');
                return $r;
            });
    }

    public function revokePersonalToken(User $actor, string $tokenId): void
    {
        $this->access->assert($actor->id, 'api.manage');
        $q = (new Query($this->db))->select(['k.id', 'k.user_id', 'k.name', 'k.revoked_at', 'u.tenant_id'])
            ->from(['k' => 'api_tokens'])->innerJoin(['u' => 'users'], 'u.id = k.user_id')
            ->where(['k.type' => 'personal', 'k.id' => Uuid::isValid($tokenId) ? $tokenId : '00000000-0000-0000-0000-000000000000']);
        $row = $q->one();
        $restriction = $this->tenantRestriction($actor);
        if ($row === null || ($restriction !== null && $row['tenant_id'] !== $restriction)) {
            throw new NotFoundException('Token not found.');
        }
        if ($row['revoked_at'] !== null) {
            return;
        }
        $this->db->transaction(function () use ($row): void {
            $this->db->createCommand()->update('api_tokens', ['revoked_at' => new Expression('now()')], ['id' => $row['id'], 'revoked_at' => null])->execute();
            $this->audit->record(
                'api_token.revoked_by_admin',
                'user',
                (string) $row['user_id'],
                null,
                null,
                ['token_id' => $row['id'], 'name' => $row['name']],
                category: 'security',
                severity: 'notice',
            );
        });
    }

    /**
     * Usage figures derived from last-use timestamps (api_tokens.last_used_at, api_clients.last_used_at).
     *
     * @return array<string,int>
     */
    public function usage(User $actor): array
    {
        $this->access->assert($actor->id, 'api.view');
        $restriction = $this->tenantRestriction($actor);
        $tenantSql = $restriction !== null ? ' AND u.tenant_id = :t' : '';
        $clientTenantSql = $restriction !== null ? ' AND c.tenant_id = :t' : '';
        $params = $restriction !== null ? [':t' => $restriction] : [];
        $row = $this->db->createCommand(<<<SQL
            SELECT
              (SELECT count(*) FROM api_clients c WHERE true{$clientTenantSql}) AS clients_total,
              (SELECT count(*) FROM api_clients c WHERE c.enabled AND (c.expires_at IS NULL OR c.expires_at > now()){$clientTenantSql}) AS clients_enabled,
              (SELECT count(*) FROM api_clients c WHERE c.last_used_at > now() - interval '24 hours'{$clientTenantSql}) AS clients_used_24h,
              (SELECT count(*) FROM api_tokens k JOIN users u ON u.id = k.user_id WHERE k.type = 'personal' AND k.revoked_at IS NULL AND k.expires_at > now(){$tenantSql}) AS personal_active,
              (SELECT count(*) FROM api_tokens k JOIN users u ON u.id = k.user_id WHERE k.type = 'personal' AND k.last_used_at > now() - interval '24 hours'{$tenantSql}) AS personal_used_24h,
              (SELECT count(*) FROM api_tokens k JOIN users u ON u.id = k.user_id WHERE k.type = 'personal' AND k.last_used_at > now() - interval '7 days'{$tenantSql}) AS personal_used_7d,
              (SELECT count(*) FROM api_tokens k JOIN users u ON u.id = k.user_id WHERE k.type = 'access' AND k.last_used_at > now() - interval '24 hours'{$tenantSql}) AS access_used_24h,
              (SELECT count(*) FROM api_tokens k JOIN users u ON u.id = k.user_id WHERE k.type = 'access' AND k.created_at > now() - interval '24 hours'{$tenantSql}) AS access_issued_24h
            SQL, $params)->queryOne();
        return array_map('intval', $row ?: []);
    }

    // ------------------------------------------------------------------ internals

    public static function generateSecret(): string
    {
        return 'yep_s_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Normalizes and validates an IP/CIDR list. @return list<string> */
    public static function parseCidrs(string|array $input, array &$errors): array
    {
        $items = is_array($input) ? $input : (preg_split('/[\s,]+/', $input) ?: []);
        $out = [];
        foreach (array_filter(array_map('trim', $items), static fn($v) => $v !== '') as $item) {
            [$addr, $bits] = array_pad(explode('/', $item, 2), 2, null);
            $bin = @inet_pton((string) $addr);
            if ($bin === false) {
                $errors[] = "Invalid IP address or CIDR: $item";
                continue;
            }
            $max = strlen($bin) * 8;
            if ($bits === null) {
                $bits = $max;
            } elseif (!ctype_digit($bits) || (int) $bits > $max) {
                $errors[] = "Invalid prefix length: $item";
                continue;
            }
            $out[] = inet_ntop($bin) . '/' . (int) $bits;
        }
        return array_values(array_unique($out));
    }

    private function validate(User $actor, array $in, bool $creating): array
    {
        $errors = [];
        $data = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 128) {
            $errors['name'][] = 'Name is required (max 128 characters).';
        }
        $data['name'] = $name;

        $scopes = is_array($in['scopes'] ?? null) ? $in['scopes'] : (preg_split('/[\s,]+/', (string) ($in['scopes'] ?? '')) ?: []);
        $scopes = array_values(array_unique(array_filter(array_map('trim', $scopes), static fn($s) => $s !== '')));
        if ($scopes === []) {
            $errors['scopes'][] = 'At least one scope is required.';
        }
        foreach ($scopes as $s) {
            if (!preg_match('/^(\*|[a-z][a-z0-9_]*(\.[a-z0-9_]+)*(\.\*)?)$/', $s) || strlen($s) > 96) {
                $errors['scopes'][] = "Invalid scope: $s";
            }
        }
        $data['scopes'] = $scopes;

        $ipErrors = [];
        $data['allowed_ips'] = self::parseCidrs($in['allowed_ips'] ?? '', $ipErrors);
        if ($ipErrors !== []) {
            $errors['allowed_ips'] = $ipErrors;
        }

        $rate = filter_var($in['rate_limit_per_minute'] ?? 600, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($rate === false) {
            $errors['rate_limit_per_minute'][] = 'Rate limit must be between 1 and 100000 requests per minute.';
        }
        $data['rate_limit_per_minute'] = (int) $rate;

        $expires = trim((string) ($in['expires_at'] ?? ''));
        if ($expires === '') {
            $data['expires_at'] = null;
        } elseif (strtotime($expires) === false) {
            $errors['expires_at'][] = 'Invalid date.';
        } elseif (strtotime($expires) <= time()) {
            $errors['expires_at'][] = 'Expiry must be in the future.';
        } else {
            $data['expires_at'] = (new DateTimeImmutable($expires))->format(DATE_ATOM);
        }

        $restriction = $this->tenantRestriction($actor);
        $tenantId = $restriction ?? (trim((string) ($in['tenant_id'] ?? '')) ?: null);
        if ($tenantId !== null && (!Uuid::isValid($tenantId) || !(new Query($this->db))->from('tenants')->where(['id' => $tenantId])->exists())) {
            $errors['tenant_id'][] = 'Unknown tenant.';
            $tenantId = null;
        }
        if ($creating) {
            $data['tenant_id'] = $tenantId;
        }

        $serviceUser = trim((string) ($in['service_username'] ?? ''));
        $data['service_user_id'] = null;
        if ($serviceUser !== '') {
            $u = (new Query($this->db))->select(['id', 'tenant_id'])->from('users')
                ->where(['deleted_at' => null])->andWhere(['=', new Expression('lower(username)'), mb_strtolower($serviceUser)])->one();
            if ($u === null || ($restriction !== null && $u['tenant_id'] !== $restriction) || ($tenantId !== null && $u['tenant_id'] !== $tenantId)) {
                $errors['service_username'][] = 'Unknown user in this tenant.';
            } else {
                $data['service_user_id'] = $u['id'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $data;
    }

    private function find(string $id): ?array
    {
        $row = (new Query($this->db))
            ->select(['c.id', 'c.name', 'c.client_id', 'c.scopes', 'c.allowed_ips', 'c.rate_limit_per_minute', 'c.enabled',
                'c.expires_at', 'c.last_used_at', 'c.created_at', 'c.updated_at', 'c.tenant_id', 'c.service_user_id',
                't.name AS tenant_name', 'u.username AS service_username'])
            ->from(['c' => 'api_clients'])
            ->leftJoin(['t' => 'tenants'], 't.id = c.tenant_id')
            ->leftJoin(['u' => 'users'], 'u.id = c.service_user_id')
            ->where(['c.id' => $id])->one();
        return $row === null ? null : self::hydrate($row);
    }

    private static function hydrate(array $r): array
    {
        $r['scopes'] = Sql::json($r['scopes']);
        $r['allowed_ips'] = Sql::json($r['allowed_ips']);
        $r['enabled'] = Sql::bool($r['enabled']);
        $r['expired'] = $r['expires_at'] !== null && strtotime((string) $r['expires_at']) <= time();
        return $r;
    }

    private static function publicRow(?array $r): ?array
    {
        return $r === null ? null : array_intersect_key($r, array_flip(['id', 'name', 'client_id', 'scopes', 'allowed_ips', 'rate_limit_per_minute', 'enabled', 'expires_at', 'tenant_id', 'service_user_id']));
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);
    }
}
