<?php

declare(strict_types=1);

namespace App\Admin\Action\Authentication;

use App\Admin\Action\Users\AdminActor;
use App\Platform\Access\AccessService;
use App\Platform\Access\RbacCatalog;
use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Sso\SsoProviderRepository;
use App\Platform\Identity\Sso\SsoService;
use App\Platform\Tenancy\TenantService;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;

/**
 * 03 · Authentication — login method switches (feature flags) and SSO provider administration.
 * Provider secrets are write-only: the UI only shows whether a secret is set.
 */
final class AuthenticationController
{
    /** Login-method switches managed here (feature flag codes). */
    public const METHODS = ['auth.local_login', 'auth.sso'];

    /** Config keys accepted per provider type (anything else is dropped). */
    public const CONFIG_FIELDS = [
        'oidc' => ['issuer', 'discovery_url', 'client_id', 'scopes', 'username_claim', 'token_auth', 'use_userinfo', 'link_by_verified_email', 'allow_insecure_http'],
        'oneid' => ['endpoint', 'client_id', 'scope'],
        'oauth2' => ['authorize_url', 'token_url', 'userinfo_url', 'client_id', 'scope', 'issuer', 'subject_claim', 'email_claim', 'name_claim', 'username_claim', 'allow_insecure_http'],
    ];

    public function __construct(
        private readonly Responder $responder,
        private readonly AdminActor $actor,
        private readonly SsoService $sso,
        private readonly SsoProviderRepository $providers,
        private readonly FeatureFlagService $flags,
        private readonly AccessService $access,
        private readonly RbacCatalog $catalog,
        private readonly TenantService $tenants,
        private readonly ConnectionInterface $db,
    ) {}

    public function index(): ResponseInterface
    {
        $actor = $this->actor->user();
        $all = $this->flags->all();
        $methods = [];
        foreach (self::METHODS as $code) {
            $methods[$code] = ($all[$code] ?? null) === null ? null : $all[$code] + ['effective' => $this->flags->isEnabled($code)];
        }
        return $this->responder->render('admin/auth/index', [
            'methods' => $methods,
            'providers' => array_values(array_filter($this->providers->all(), fn(array $p) => $this->visible($actor, $p))),
            'types' => $this->sso->types(),
            'history' => (new Query($this->db))
                ->select(['occurred_at', 'action', 'result', 'actor_name', 'actor_id', 'entity_type', 'entity_id'])
                ->from('audit_log')
                ->where(['or', ['like', 'action', 'sso.%', false], ['and', ['entity_type' => 'feature_flag'], ['entity_id' => self::METHODS]]])
                ->andWhere(['>', 'occurred_at', (new \DateTimeImmutable('-90 days'))->format(DATE_ATOM)])
                ->orderBy(['occurred_at' => SORT_DESC])->limit(15)->all(),
            'canSwitch' => $this->access->can($actor->id, 'settings.manage'),
        ]);
    }

    public function setMethod(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $code = (string) $route->getArgument('method');
        if (!in_array($code, self::METHODS, true)) {
            throw new NotFoundException('Unknown login method.');
        }
        $enable = filter_var(AdminActor::body($request)['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        try {
            if ($enable) {
                $this->flags->enable($actor->id, $code);
            } else {
                $other = $code === 'auth.local_login' ? 'auth.sso' : 'auth.local_login';
                if (!$this->flags->isEnabled($other)) {
                    throw new ConflictException('At least one login method must stay enabled.', 'last_login_method');
                }
                if ($code === 'auth.local_login' && $this->providers->enabledForLogin() === []) {
                    throw new ConflictException('Enable and test at least one SSO provider before switching off local login.', 'no_sso_provider');
                }
                $this->flags->disable($actor->id, $code);
            }
        } catch (ConflictException|ValidationException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.auth');
        }
        $this->responder->success($enable ? 'Login method enabled.' : 'Login method disabled.');
        return $this->responder->redirect('admin.auth');
    }

    public function createForm(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) (AdminActor::query($request, ['type'])['type'] ?: 'oidc');
        if (!isset(self::CONFIG_FIELDS[$type])) {
            $type = 'oidc';
        }
        return $this->form(null, ['type' => $type, 'config' => self::defaults($type), 'default_role' => 'user', 'sort_order' => 0], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $body = AdminActor::body($request);
        $input = $body + ['type' => 'oidc'];
        try {
            $input = $this->input($actor, $body, (string) ($body['type'] ?? ''));
            $code = $this->sso->save($actor->id, null, $input);
        } catch (ValidationException $e) {
            return $this->form(null, $input, $e->errors(), 422);
        } catch (\Yiisoft\Db\Exception\IntegrityException) {
            return $this->form(null, $input, ['code' => ['A provider with this code already exists.']], 422);
        }
        $this->responder->success('Provider saved. Run "Test connection" before enabling it.');
        return $this->responder->redirect('admin.auth.providers.view', ['code' => $code]);
    }

    public function view(CurrentRoute $route): ResponseInterface
    {
        $provider = $this->load((string) $route->getArgument('code'));
        return $this->responder->render('admin/auth/provider', [
            'provider' => $provider,
            'types' => $this->sso->types(),
            'required' => $this->sso->adapter((string) $provider['type'])->requiredConfig(),
            'callback' => $this->callbackUrl($provider),
            'linkedCount' => (int) (new Query($this->db))->from('user_identities')->where(['provider_code' => $provider['code']])->count(),
            'history' => (new Query($this->db))
                ->select(['occurred_at', 'action', 'result', 'actor_name', 'actor_id', 'after', 'metadata'])
                ->from('audit_log')
                ->where(['entity_type' => 'sso_provider', 'entity_id' => $provider['code']])
                ->orderBy(['occurred_at' => SORT_DESC])->limit(30)->all(),
            'failures' => (int) $this->db->createCommand(
                "SELECT count(*) FROM audit_log WHERE action = 'auth.sso.failure' AND occurred_at > now() - interval '24 hours' AND metadata->>'provider' = :p",
                [':p' => $provider['code']],
            )->queryScalar(),
        ]);
    }

    public function editForm(CurrentRoute $route): ResponseInterface
    {
        $provider = $this->load((string) $route->getArgument('code'));
        $provider['redirect_allowlist'] = implode("\n", $provider['redirect_allowlist']);
        return $this->form((string) $provider['code'], $provider, []);
    }

    public function update(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $provider = $this->load((string) $route->getArgument('code'));
        $input = AdminActor::body($request) + ['type' => $provider['type']];
        try {
            $input = $this->input($actor, AdminActor::body($request), (string) $provider['type']);
            $this->sso->save($actor->id, (string) $provider['code'], $input);
        } catch (ValidationException $e) {
            return $this->form((string) $provider['code'], $input + ['has_secret' => $provider['has_secret']], $e->errors(), 422);
        }
        $this->responder->success('Provider updated.');
        return $this->responder->redirect('admin.auth.providers.view', ['code' => $provider['code']]);
    }

    public function enable(CurrentRoute $route): ResponseInterface
    {
        return $this->toggle($route, true);
    }

    public function disable(CurrentRoute $route): ResponseInterface
    {
        return $this->toggle($route, false);
    }

    public function test(CurrentRoute $route): ResponseInterface
    {
        $provider = $this->load((string) $route->getArgument('code'));
        $result = $this->sso->test($this->actor->user()->id, (string) $provider['code']);
        if ($result['status'] === 'healthy') {
            $this->responder->success('Connection test succeeded.');
        } else {
            $detail = $result['details']['missing'] ?? null;
            $this->responder->error($detail !== null
                ? 'Not configured — missing: ' . implode(', ', (array) $detail)
                : 'Connection test failed (' . $result['status'] . '). See the health details below.');
        }
        return $this->responder->redirect('admin.auth.providers.view', ['code' => $provider['code']]);
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $provider = $this->load((string) $route->getArgument('code'));
        $this->sso->delete($this->actor->user()->id, (string) $provider['code']);
        $this->responder->success('Provider deleted. Existing identity links were kept for audit and can be removed per user.');
        return $this->responder->redirect('admin.auth');
    }

    private function toggle(CurrentRoute $route, bool $enabled): ResponseInterface
    {
        $provider = $this->load((string) $route->getArgument('code'));
        try {
            $this->sso->setEnabled($this->actor->user()->id, (string) $provider['code'], $enabled);
        } catch (ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('admin.auth.providers.view', ['code' => $provider['code']]);
        }
        $this->responder->success($enabled ? 'Provider enabled.' : 'Provider disabled.');
        return $this->responder->redirect('admin.auth.providers.view', ['code' => $provider['code']]);
    }

    /** Provider visible to the actor (tenant-bound providers of other tenants are hidden). */
    private function visible(User $actor, array $provider): bool
    {
        $restriction = $this->tenants->tenantRestriction($actor);
        return $restriction === null || $provider['tenant_id'] === null || $provider['tenant_id'] === $restriction;
    }

    private function load(string $code): array
    {
        $provider = preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code) ? $this->providers->find($code) : null;
        if ($provider === null || !$this->visible($this->actor->user(), $provider)) {
            throw new NotFoundException('Provider not found.');
        }
        unset($provider['secret']);
        return $provider;
    }

    /** Whitelists the submitted fields for SsoService::save(). */
    private function input(User $actor, array $body, string $type): array
    {
        $type = isset(self::CONFIG_FIELDS[$type]) ? $type : 'oidc';
        $config = is_array($body['config'] ?? null) ? $body['config'] : [];
        $config = array_intersect_key($config, array_flip(self::CONFIG_FIELDS[$type]));
        $config = array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $config);
        $restriction = $this->tenants->tenantRestriction($actor);
        $tenantId = $restriction ?? (is_string($body['tenant_id'] ?? null) ? trim($body['tenant_id']) : '');
        if ($tenantId !== '' && $restriction === null) {
            try {
                $this->tenants->load($actor, $tenantId);
            } catch (NotFoundException) {
                throw ValidationException::single('tenant_id', 'Unknown tenant.');
            }
        }
        return [
            'type' => $type,
            'code' => is_string($body['code'] ?? null) ? $body['code'] : '',
            'name' => is_string($body['name'] ?? null) ? $body['name'] : '',
            'config' => $config,
            'secret' => is_string($body['secret'] ?? null) ? $body['secret'] : '',
            'redirect_allowlist' => is_string($body['redirect_allowlist'] ?? null) ? $body['redirect_allowlist'] : '',
            'auto_provision' => !empty($body['auto_provision']),
            'default_role' => is_string($body['default_role'] ?? null) ? $body['default_role'] : 'user',
            'sort_order' => (int) ($body['sort_order'] ?? 0),
            'tenant_id' => $tenantId,
        ];
    }

    private function form(?string $code, array $values, array $errors, int $status = 200): ResponseInterface
    {
        $actor = $this->actor->user();
        unset($values['secret']);
        $type = (string) ($values['type'] ?? 'oidc');
        $type = isset(self::CONFIG_FIELDS[$type]) ? $type : 'oidc';
        $values['type'] = $type;
        $values['config'] = is_array($values['config'] ?? null) ? $values['config'] : [];
        $roles = array_values(array_diff(array_keys($this->catalog->roles()), RbacCatalog::ADMIN_ROLES));
        return $this->responder->render('admin/auth/form', [
            'code' => $code,
            'values' => $values,
            'errors' => $errors,
            'type' => $type,
            'types' => $this->sso->types(),
            'fields' => self::CONFIG_FIELDS[$type] ?? [],
            'required' => $this->sso->adapter($type)->requiredConfig(),
            'roles' => array_combine($roles, $roles),
            'tenants' => $this->tenants->options($actor),
            'cross' => $this->tenants->tenantRestriction($actor) === null,
            'prod' => \App\Environment::isProd(),
        ], status: $status);
    }

    private function callbackUrl(array $provider): ?string
    {
        try {
            return $this->sso->redirectUri($provider);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function defaults(string $type): array
    {
        return match ($type) {
            'oidc' => ['scopes' => 'openid profile email', 'username_claim' => 'preferred_username', 'token_auth' => 'client_secret_basic', 'use_userinfo' => false, 'link_by_verified_email' => false],
            'oneid' => [], // endpoint, client_id/secret and scope are issued by the OneID operator — nothing is prefilled
            'oauth2' => ['subject_claim' => 'sub', 'email_claim' => 'email', 'name_claim' => 'name', 'username_claim' => 'login'],
            default => [],
        };
    }
}
