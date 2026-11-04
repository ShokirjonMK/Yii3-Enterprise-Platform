<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * Section 05 · Multi-Tenancy.
 */
final class TenantsAdminTest extends TestCase
{
    /** @var list<string> */
    private array $codes = [];

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
        $db = Fixtures::db();
        foreach ($this->codes as $code) {
            $id = $db->createCommand('SELECT id FROM tenants WHERE code = :c', [':c' => $code])->queryScalar();
            if ($id) {
                $db->createCommand()->delete('users', ['tenant_id' => $id])->execute();
                $db->createCommand()->delete('tenants', ['id' => $id])->execute();
            }
        }
    }

    public function testCrudSuspendRevokesSessionsAndFlagOverride(): void
    {
        [$web, $admin] = AdminSession::login(['superadmin']);
        $code = 't-' . bin2hex(random_bytes(4));
        $this->codes[] = $code;
        $db = Fixtures::db();

        $web->get('/admin/tenants/create');
        $r = $web->post('/admin/tenants/create', ['code' => 'Bad Code!', 'name' => '']);
        self::assertSame(422, $r->getStatusCode());
        $web->post('/admin/tenants/create', ['code' => $code, 'name' => 'E2E Tenant', 'domain' => $code . '.example.uz']);
        $id = (string) $db->createCommand('SELECT id FROM tenants WHERE code = :c', [':c' => $code])->queryScalar();
        self::assertNotSame('', $id);
        self::assertSame('/admin/tenants/' . $id, $web->url());

        $web->get('/admin/tenants?q=' . $code);
        self::assertStringContainsString($code, $web->body());
        $r = $web->htmx('GET', '/admin/tenants?q=' . $code . '&sort=-users');
        self::assertStringStartsWith('<main id="main"', ltrim($web->body()));

        $web->get("/admin/tenants/$id/edit");
        $web->post("/admin/tenants/$id/edit", ['name' => 'E2E Tenant renamed', 'domain' => '']);
        self::assertSame('E2E Tenant renamed', $db->createCommand('SELECT name FROM tenants WHERE id = :id', [':id' => $id])->queryScalar());

        // A member with an open session
        [$memberWeb, $member] = $this->memberSession($id);
        self::assertSame(200, $memberWeb->get('/account')->getStatusCode());

        // Suspend: critical → re-auth, then sessions of tenant users are revoked
        self::expireReauth($admin['id']);
        $web->get("/admin/tenants/$id");
        $web->post("/admin/tenants/$id/status", ['status' => 'suspended']);
        self::assertSame('/auth/confirm', $web->url());
        AdminSession::reauth($web, $admin);
        $web->get("/admin/tenants/$id");
        $web->post("/admin/tenants/$id/status", ['status' => 'suspended']);
        self::assertSame('suspended', $db->createCommand('SELECT status FROM tenants WHERE id = :id', [':id' => $id])->queryScalar());
        self::assertSame(0, (int) $db->createCommand('SELECT count(*) FROM user_sessions WHERE user_id = :u AND revoked_at IS NULL', [':u' => $member['id']])->queryScalar());
        $memberWeb->get('/account');
        self::assertSame('/login', $memberWeb->url(), 'member was signed out');

        // Per-tenant feature flag override
        $web->get("/admin/tenants/$id");
        $web->post("/admin/tenants/$id/flags", ['code' => 'auth.sso', 'value' => 'off']);
        $flagId = (string) $db->createCommand("SELECT id FROM feature_flags WHERE code = 'auth.sso'")->queryScalar();
        self::assertFalse((bool) $db->createCommand('SELECT enabled FROM feature_flag_overrides WHERE flag_id = :f AND tenant_id = :t', [':f' => $flagId, ':t' => $id])->queryScalar());
        $web->post("/admin/tenants/$id/flags", ['code' => 'auth.sso', 'value' => 'inherit']);
        self::assertFalse($db->createCommand('SELECT 1 FROM feature_flag_overrides WHERE flag_id = :f AND tenant_id = :t', [':f' => $flagId, ':t' => $id])->queryScalar());

        $web->post("/admin/tenants/$id/status", ['status' => 'active']);
        self::assertSame('active', $db->createCommand('SELECT status FROM tenants WHERE id = :id', [':id' => $id])->queryScalar());

        $actions = (new Query($db))->select('action')->from('audit_log')->where(['entity_type' => 'tenant', 'entity_id' => $id])->column();
        self::assertContains('tenant.created', $actions);
        self::assertContains('tenant.updated', $actions);
        self::assertContains('tenant.status.changed', $actions);

        // Default tenant is protected
        $default = (string) $db->createCommand("SELECT id FROM tenants WHERE code = 'default'")->queryScalar();
        $web->get("/admin/tenants/$default");
        $web->post("/admin/tenants/$default/status", ['status' => 'suspended']);
        self::assertSame('active', $db->createCommand('SELECT status FROM tenants WHERE id = :id', [':id' => $default])->queryScalar());
    }

    public function testScopedViewerSeesOnlyOwnTenantAndLowerRolesDenied(): void
    {
        $db = Fixtures::db();
        $code = 't-' . bin2hex(random_bytes(4));
        $this->codes[] = $code;
        $foreign = (string) $db->createCommand("INSERT INTO tenants (code, name) VALUES (:c, 'E2E foreign') RETURNING id", [':c' => $code])->queryScalar();

        [$web] = AdminSession::login(['auditor']); // tenants.view, no tenants.cross
        self::assertSame(200, $web->get('/admin/tenants')->getStatusCode());
        self::assertStringNotContainsString($code, $web->body());
        self::assertSame(404, $web->get("/admin/tenants/$foreign")->getStatusCode());
        self::assertSame(403, $web->get('/admin/tenants/create')->getStatusCode());
        $web->get('/admin/tenants');
        self::assertSame(403, $web->post("/admin/tenants/$foreign/edit", ['name' => 'hijack'])->getStatusCode());
        self::assertSame('E2E foreign', $db->createCommand('SELECT name FROM tenants WHERE id = :id', [':id' => $foreign])->queryScalar());

        [$dev] = AdminSession::login(['developer']);
        self::assertSame(403, $dev->get('/admin/tenants')->getStatusCode());
    }

    /** @return array{0:\App\Tests\Support\WebClient, 1:array} */
    private function memberSession(string $tenantId): array
    {
        $member = Fixtures::user(['user'], extra: ['tenant_id' => $tenantId]);
        $web = new \App\Tests\Support\WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $member['username'], 'password' => $member['password']]);
        return [$web, $member];
    }

    private static function expireReauth(string $userId): void
    {
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $userId])->execute();
    }
}
