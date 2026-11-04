<?php

declare(strict_types=1);

namespace App\Platform\Identity\Sso;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Domain\AuthPolicy;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Setup\PlatformSeeder;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Security\Encrypter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * SSO orchestration and provider administration.
 *
 * Account linking rules (secure by default):
 *  1. existing link (issuer + subject) → that user;
 *  2. otherwise, if the provider has `link_by_verified_email` AND the IdP asserts email_verified=true
 *     AND exactly one active user has that e-mail AND that user's policy allows SSO → link;
 *  3. otherwise, if `auto_provision` is on → create an SSO-only user with the provider's default role;
 *  4. otherwise → refuse ("account not linked").
 * Users with auth_policy=local_only can never sign in through SSO.
 */
final class SsoService
{
    /** @var array<string, SsoProviderInterface> */
    private array $adapters = [];

    public function __construct(
        private readonly SsoProviderRepository $providers,
        private readonly ConnectionInterface $db,
        private readonly UserRepository $users,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
        private readonly Encrypter $encrypter,
        private readonly string $baseUrl,
        OidcProvider $oidc,
        OneIdProvider $oneId,
        OAuth2Provider $oauth2,
    ) {
        foreach ([$oidc, $oneId, $oauth2] as $a) {
            $this->adapters[$a->type()] = $a;
        }
    }

    public function adapter(string $type): SsoProviderInterface
    {
        return $this->adapters[$type] ?? throw new ValidationException(['type' => ['Unsupported provider type.']]);
    }

    /** @return array<string,string> */
    public function types(): array
    {
        return ['oidc' => 'OpenID Connect', 'oneid' => 'OneID (id.egov.uz)', 'oauth2' => 'OAuth 2.0 (generic)'];
    }

    public function redirectUri(array $provider): string
    {
        $uri = $this->baseUrl . '/auth/sso/' . $provider['code'] . '/callback';
        $allow = $provider['redirect_allowlist'] ?? [];
        if ($allow !== [] && !in_array($uri, $allow, true)) {
            throw new SsoException('Callback URL is not in the provider redirect allowlist.', 'redirect_not_allowed');
        }
        return $uri;
    }

    /** @return array{url:string, state:array} */
    public function start(string $code): array
    {
        $provider = $this->providers->find($code, true);
        if ($provider === null || !$provider['enabled'] || in_array($provider['status'], ['failed', 'maintenance', 'unconfigured'], true)) {
            throw new NotFoundException('This sign-in method is not available.');
        }
        $req = $this->adapter($provider['type'])->authorizationRequest($provider, $this->redirectUri($provider));
        $req['state']['provider'] = $code;
        $req['state']['at'] = time();
        return $req;
    }

    public function complete(string $code, array $query, array $state): User
    {
        if (($state['provider'] ?? null) !== $code || ($state['at'] ?? 0) < time() - 600) {
            throw new SsoException('Invalid or expired sign-in state. Please try again.', 'invalid_state');
        }
        $provider = $this->providers->find($code, true);
        if ($provider === null || !$provider['enabled']) {
            throw new NotFoundException('This sign-in method is not available.');
        }
        try {
            $ext = $this->adapter($provider['type'])->handleCallback($provider, $query, $state, $this->redirectUri($provider));
        } catch (SsoException $e) {
            $this->audit->security('auth.sso.failure', 'failure', ['provider' => $code, 'reason' => $e->reason()], 'notice');
            throw $e;
        }
        return $this->resolveUser($provider, $ext);
    }

    public function resolveUser(array $provider, ExternalIdentity $ext): User
    {
        return $this->db->transaction(function () use ($provider, $ext): User {
            $link = (new Query($this->db))->from('user_identities')->where(['issuer' => $ext->issuer, 'subject' => $ext->subject])->one();
            $user = $link !== null ? $this->users->find((string) $link['user_id']) : null;

            if ($user === null && !empty($provider['config']['link_by_verified_email']) && $ext->emailVerified && $ext->email !== null) {
                $candidate = $this->users->findByLogin($ext->email);
                if ($candidate !== null && strcasecmp((string) $candidate->email, $ext->email) === 0) {
                    $user = $candidate;
                }
            }

            if ($user === null) {
                if (!$provider['auto_provision']) {
                    $this->audit->security('auth.sso.not_linked', 'denied', ['provider' => $provider['code']], 'notice');
                    throw new SsoException('Your account is not linked to this sign-in method. Contact the administrator.', 'not_linked');
                }
                $user = $this->provision($provider, $ext);
            }

            if (!$user->isActive()) {
                throw new SsoException('Your account is not active.', 'inactive');
            }
            if (!$this->users->isTenantActive($user->tenantId)) {
                throw new SsoException('Your organization is suspended.', 'tenant_inactive');
            }
            if ($user->authPolicy === AuthPolicy::LOCAL_ONLY) {
                throw new SsoException('This account may not use single sign-on.', 'policy_denied');
            }
            if ($provider['tenant_id'] !== null && $user->tenantId !== null && $user->tenantId !== $provider['tenant_id']) {
                throw new SsoException('This sign-in method is not available for your organization.', 'tenant_mismatch');
            }

            $this->db->createCommand(
                'INSERT INTO user_identities (user_id, provider_code, issuer, subject, email, claims, last_used_at)
                 VALUES (:u, :p, :i, :s, :e, CAST(:c AS jsonb), now())
                 ON CONFLICT (issuer, subject) DO UPDATE
                 SET provider_code = EXCLUDED.provider_code, email = EXCLUDED.email, claims = EXCLUDED.claims, last_used_at = now()',
                [
                    ':u' => $user->id, ':p' => $provider['code'], ':i' => $ext->issuer, ':s' => $ext->subject, ':e' => $ext->email,
                    ':c' => json_encode(\App\Shared\Infrastructure\Logging\Redactor::redact($ext->claims), JSON_UNESCAPED_UNICODE),
                ],
            )->execute();
            return $user;
        });
    }

    private function provision(array $provider, ExternalIdentity $ext): User
    {
        $tenantId = $provider['tenant_id'] ?? (new Query($this->db))->select('id')->from('tenants')->where(['code' => PlatformSeeder::DEFAULT_TENANT])->scalar() ?: null;
        $base = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($ext->username ?? ($ext->email ? strstr($ext->email, '@', true) : '') ?: $provider['code'] . '-user'));
        $base = strlen($base) < 3 ? $provider['code'] . '-' . substr(sha1($ext->subject), 0, 8) : substr($base, 0, 56);
        $username = $base;
        for ($i = 1; $this->users->findByLogin($username) !== null; $i++) {
            $username = $base . '-' . $i;
        }
        $email = $ext->email !== null && $this->users->findByLogin($ext->email) === null ? $ext->email : null;
        $id = $this->users->insert([
            'username' => $username,
            'email' => $email,
            'full_name' => mb_substr((string) $ext->name, 0, 255),
            'auth_policy' => AuthPolicy::SSO_ONLY,
            'tenant_id' => $tenantId,
        ]);
        $this->access->assignSystem($id, 'user');
        if ($provider['default_role'] !== 'user' && !in_array($provider['default_role'], \App\Platform\Access\RbacCatalog::ADMIN_ROLES, true)) {
            $this->access->assignSystem($id, (string) $provider['default_role']);
        }
        $this->audit->record('user.provisioned_via_sso', 'user', $id, null, ['username' => $username, 'provider' => $provider['code']], category: 'security', severity: 'notice', actorId: $id);
        return $this->users->find($id);
    }

    // ------------------------------------------------------------------ administration

    public function save(string $actorId, ?string $code, array $input): string
    {
        $this->access->assert($actorId, 'auth.manage');
        $existing = $code === null ? null : $this->providers->find($code, true);
        if ($code !== null && $existing === null) {
            throw new NotFoundException('Provider not found.');
        }
        $type = $existing['type'] ?? (string) ($input['type'] ?? '');
        $adapter = $this->adapter($type);
        $errors = [];
        $newCode = $existing['code'] ?? strtolower(trim((string) ($input['code'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $newCode)) {
            $errors['code'][] = 'Code must be 2–64 lowercase letters, digits, - or _.';
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'][] = 'Name is required.';
        }
        $config = is_array($input['config'] ?? null) ? array_map(static fn($v) => is_string($v) ? trim($v) : $v, $input['config']) : [];
        foreach (['endpoint', 'issuer', 'discovery_url', 'authorize_url', 'token_url', 'userinfo_url'] as $urlKey) {
            if (!empty($config[$urlKey]) && $urlKey !== 'issuer' && !preg_match('#^https://#', (string) $config[$urlKey]) && empty($config['allow_insecure_http'])) {
                $errors["config.$urlKey"][] = 'Must be an https:// URL.';
            }
        }
        foreach (['link_by_verified_email', 'use_userinfo', 'allow_insecure_http'] as $boolKey) {
            if (array_key_exists($boolKey, $config)) {
                $config[$boolKey] = filter_var($config[$boolKey], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (!empty($config['allow_insecure_http']) && \App\Environment::isProd()) {
            $errors['config.allow_insecure_http'][] = 'Insecure HTTP is not allowed in production.';
        }
        $defaultRole = (string) ($input['default_role'] ?? 'user');
        if (in_array($defaultRole, \App\Platform\Access\RbacCatalog::ADMIN_ROLES, true)) {
            $errors['default_role'][] = 'Administrative roles cannot be granted automatically.';
        }
        $allowlist = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($input['redirect_allowlist'] ?? '')) ?: [])));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $missing = array_filter($adapter->requiredConfig(), static fn($k) => empty($config[$k]));
        $row = [
            'code' => $newCode,
            'type' => $type,
            'name' => $name,
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'redirect_allowlist' => json_encode($allowlist, JSON_UNESCAPED_SLASHES),
            'auto_provision' => filter_var($input['auto_provision'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'default_role' => $defaultRole,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
            'tenant_id' => !empty($input['tenant_id']) ? (string) $input['tenant_id'] : null,
        ];
        $secret = (string) ($input['secret'] ?? '');
        if ($secret !== '') {
            $row['secret_enc'] = $this->encrypter->encrypt($secret, 'sso');
        }
        if ($missing !== []) {
            $row['status'] = 'unconfigured';
            $row['enabled'] = false;
        } elseif (($existing['status'] ?? 'unconfigured') === 'unconfigured') {
            $row['status'] = 'degraded'; // configured but not yet tested
        }

        $this->db->transaction(function () use ($existing, $row, $newCode): void {
            if ($existing === null) {
                $this->db->createCommand()->insert('sso_providers', $row)->execute();
            } else {
                $row['version'] = new Expression('version + 1');
                $this->db->createCommand()->update('sso_providers', $row, ['code' => $newCode])->execute();
            }
            $after = $row;
            unset($after['secret_enc']);
            $this->audit->record($existing === null ? 'sso.provider.created' : 'sso.provider.updated', 'sso_provider', $newCode,
                $existing === null ? null : array_diff_key($existing, ['secret' => 1]), $after + ['secret_changed' => isset($row['secret_enc'])],
                category: 'security', severity: 'warning');
        });
        return $newCode;
    }

    public function setEnabled(string $actorId, string $code, bool $enabled): void
    {
        $this->access->assert($actorId, 'auth.manage');
        $provider = $this->providers->find($code, true) ?? throw new NotFoundException('Provider not found.');
        if ($enabled) {
            $missing = array_filter($this->adapter($provider['type'])->requiredConfig(), static fn($k) => empty($provider['config'][$k]));
            if ($missing !== []) {
                throw new ConflictException('Provider is not fully configured: ' . implode(', ', $missing), 'unconfigured');
            }
            if ($provider['status'] === 'failed') {
                throw new ConflictException('The last connection test failed. Fix the configuration and run "Test connection" first.', 'unhealthy');
            }
        }
        $this->db->createCommand()->update('sso_providers', ['enabled' => $enabled, 'version' => new Expression('version + 1')], ['code' => $code])->execute();
        $this->audit->record($enabled ? 'sso.provider.enabled' : 'sso.provider.disabled', 'sso_provider', $code, category: 'security', severity: 'warning');
    }

    /** @return array{status:string, details:array} */
    public function test(string $actorId, string $code): array
    {
        $this->access->assert($actorId, 'auth.manage');
        $provider = $this->providers->find($code, true) ?? throw new NotFoundException('Provider not found.');
        $missing = array_filter($this->adapter($provider['type'])->requiredConfig(), static fn($k) => empty($provider['config'][$k]));
        $result = $missing !== []
            ? ['status' => 'unconfigured', 'details' => ['missing' => array_values($missing)]]
            : $this->adapter($provider['type'])->testConnection($provider);
        $this->db->createCommand()->update('sso_providers', [
            'status' => $result['status'],
            'last_health_at' => new Expression('now()'),
            'last_health_error' => $result['status'] === 'healthy' ? null : mb_substr(json_encode($result['details']) ?: '', 0, 2000),
        ], ['code' => $code])->execute();
        $this->audit->record('sso.provider.tested', 'sso_provider', $code, null, ['status' => $result['status']], category: 'security');
        return $result;
    }

    public function delete(string $actorId, string $code): void
    {
        $this->access->assert($actorId, 'auth.manage');
        $this->db->transaction(function () use ($code): void {
            $this->db->createCommand()->delete('sso_providers', ['code' => $code])->execute();
            $this->audit->record('sso.provider.deleted', 'sso_provider', $code, category: 'security', severity: 'warning');
        });
    }

    public function unlink(string $actorId, string $identityId): void
    {
        $this->access->assert($actorId, 'users.manage');
        $row = (new Query($this->db))->from('user_identities')->where(['id' => $identityId])->one() ?? throw new NotFoundException('Link not found.');
        $this->db->createCommand()->delete('user_identities', ['id' => $identityId])->execute();
        $this->audit->record('sso.identity.unlinked', 'user', (string) $row['user_id'], ['provider' => $row['provider_code']], null, category: 'security', severity: 'notice');
    }
}
