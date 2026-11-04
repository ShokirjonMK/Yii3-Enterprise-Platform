<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * Section 03 · Authentication: SSO provider administration and login-method switches.
 */
final class AuthAdminTest extends TestCase
{
    private ?string $code = null;

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        if ($this->code !== null) {
            Fixtures::db()->createCommand()->delete('sso_providers', ['code' => $this->code])->execute();
        }
        Fixtures::cleanup();
    }

    public function testProviderLifecycleSecretNeverRendered(): void
    {
        [$web, $admin] = AdminSession::login(['superadmin']);
        $this->code = 'e2e-' . bin2hex(random_bytes(3));
        $secret = 'Sup3r-Secret-' . bin2hex(random_bytes(8));

        $r = $web->get('/admin/auth/new-provider?type=oidc');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('name="config[issuer]"', $web->body());
        self::assertSame(200, $web->get('/admin/auth/new-provider?type=oneid')->getStatusCode());
        self::assertStringContainsString('name="config[endpoint]"', $web->body());

        // Saving is a critical change: re-authentication first
        self::expireReauth($admin['id']);
        $web->get('/admin/auth/new-provider?type=oidc');
        $web->post('/admin/auth/new-provider', ['type' => 'oidc', 'code' => $this->code, 'name' => 'E2E IdP', 'secret' => $secret]);
        self::assertSame('/auth/confirm', $web->url());
        self::assertFalse((new Query(Fixtures::db()))->from('sso_providers')->where(['code' => $this->code])->exists());

        AdminSession::reauth($web, $admin);
        $web->get('/admin/auth/new-provider?type=oidc');
        $r = $web->post('/admin/auth/new-provider', [
            'type' => 'oidc', 'code' => $this->code, 'name' => 'E2E IdP', 'secret' => $secret,
            'config' => ['issuer' => 'https://idp.e2e.invalid/realms/x', 'client_id' => 'yep-e2e', 'scopes' => 'openid email', 'injected_key' => 'evil'],
            'default_role' => 'user',
        ]);
        self::assertSame(200, $r->getStatusCode(), $web->body());
        self::assertSame('/admin/auth/providers/' . $this->code, $web->url());
        self::assertStringNotContainsString($secret, $web->body(), 'secret never rendered');
        self::assertStringContainsString('set', $web->body());

        $row = (new Query(Fixtures::db()))->from('sso_providers')->where(['code' => $this->code])->one();
        self::assertNotEmpty($row['secret_enc']);
        self::assertStringNotContainsString($secret, (string) $row['secret_enc'], 'secret stored encrypted');
        self::assertStringNotContainsString('injected_key', (string) $row['config'], 'only whitelisted config keys are stored');

        $web->get('/admin/auth/providers/' . $this->code . '/edit');
        self::assertStringNotContainsString($secret, $web->body());
        $web->get('/admin/auth');
        self::assertStringNotContainsString($secret, $web->body());
        self::assertStringContainsString($this->code, $web->body());

        // Connection test against an unresolvable issuer → failed → cannot be enabled
        $web->get('/admin/auth/providers/' . $this->code);
        $web->post('/admin/auth/providers/' . $this->code . '/test');
        self::assertSame('failed', (new Query(Fixtures::db()))->select('status')->from('sso_providers')->where(['code' => $this->code])->scalar());
        $web->post('/admin/auth/providers/' . $this->code . '/enable');
        self::assertFalse((bool) (new Query(Fixtures::db()))->select('enabled')->from('sso_providers')->where(['code' => $this->code])->scalar());

        // Administrative default role is refused
        $web->get('/admin/auth/providers/' . $this->code . '/edit');
        $r = $web->post('/admin/auth/providers/' . $this->code . '/edit', ['name' => 'E2E IdP', 'default_role' => 'superadmin', 'config' => ['issuer' => 'https://idp.e2e.invalid/realms/x', 'client_id' => 'yep-e2e']]);
        self::assertSame(422, $r->getStatusCode());

        $audit = (new Query(Fixtures::db()))->select(['action', 'before', 'after'])->from('audit_log')->where(['entity_type' => 'sso_provider', 'entity_id' => $this->code])->all();
        self::assertContains('sso.provider.created', array_column($audit, 'action'));
        self::assertContains('sso.provider.tested', array_column($audit, 'action'));
        self::assertStringNotContainsString($secret, json_encode($audit), 'secret not in audit');

        $web->get('/admin/auth/providers/' . $this->code);
        $web->post('/admin/auth/providers/' . $this->code . '/delete');
        self::assertFalse((new Query(Fixtures::db()))->from('sso_providers')->where(['code' => $this->code])->exists());
    }

    public function testLowerRolesDeniedAndCsrfRequired(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/auth')->getStatusCode());
        self::assertSame(403, $web->get('/admin/auth/new-provider')->getStatusCode());
        $web->get('/admin/auth');
        self::assertSame(403, $web->post('/admin/auth/methods/auth.sso', ['enabled' => '0'])->getStatusCode());

        [$dev] = AdminSession::login(['developer']);
        self::assertSame(403, $dev->get('/admin/auth')->getStatusCode());

        [$sa, $admin] = AdminSession::login(['superadmin']);
        AdminSession::reauth($sa, $admin);
        $sa->get('/admin/auth');
        $r = $sa->post('/admin/auth/methods/auth.sso', ['enabled' => '0'], [], false);
        self::assertContains($r->getStatusCode(), [400, 403, 422]);
        self::assertTrue((bool) Fixtures::db()->createCommand("SELECT enabled FROM feature_flags WHERE code = 'auth.sso'")->queryScalar());
    }

    public function testLoginMethodGuards(): void
    {
        [$web, $admin] = AdminSession::login(['superadmin']);
        AdminSession::reauth($web, $admin);
        $db = Fixtures::db();
        $hasEnabledProvider = (new Query($db))->from('sso_providers')->where(['enabled' => true])->andWhere(['not in', 'status', ['failed', 'maintenance', 'unconfigured']])->exists();
        if (!$hasEnabledProvider) {
            $web->get('/admin/auth');
            $web->post('/admin/auth/methods/auth.local_login', ['enabled' => '0']);
            self::assertStringContainsString('before switching off local login', $web->body());
            self::assertTrue((bool) $db->createCommand("SELECT enabled FROM feature_flags WHERE code = 'auth.local_login'")->queryScalar(), 'local login stays on without a usable SSO provider');
        }
        try {
            $web->get('/admin/auth');
            $web->post('/admin/auth/methods/auth.sso', ['enabled' => '0']);
            self::assertFalse((bool) $db->createCommand("SELECT enabled FROM feature_flags WHERE code = 'auth.sso'")->queryScalar());
            $web->post('/admin/auth/methods/auth.sso', ['enabled' => '1']);
        } finally {
            if (!(bool) $db->createCommand("SELECT enabled FROM feature_flags WHERE code = 'auth.sso'")->queryScalar()) {
                $db->createCommand("UPDATE feature_flags SET enabled = true, status = 'enabled', version = version + 1 WHERE code = 'auth.sso'")->execute();
                $db->createCommand('UPDATE config_revision SET revision = revision + 1, changed_at = now()')->execute();
            }
        }
        self::assertTrue((bool) $db->createCommand("SELECT enabled FROM feature_flags WHERE code = 'auth.sso'")->queryScalar());
    }

    private static function expireReauth(string $userId): void
    {
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $userId])->execute();
    }
}
