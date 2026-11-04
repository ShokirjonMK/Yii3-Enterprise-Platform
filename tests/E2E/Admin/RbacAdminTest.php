<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * Section 04 · RBAC & Access Control.
 */
final class RbacAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testRolesMatrixAndRolePage(): void
    {
        [$web] = AdminSession::login(['auditor']);
        $r = $web->get('/admin/rbac');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('superadmin', $web->body());
        self::assertStringNotContainsString('Sync catalog', $web->body(), 'auditor cannot sync');

        $r = $web->get('/admin/rbac/matrix');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('users.manage', $web->body());
        self::assertStringContainsString('id-yes', $web->body());

        $r = $web->get('/admin/rbac/roles/auditor');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('audit.view', $web->body());
        self::assertSame(404, $web->get('/admin/rbac/roles/nonexistent_role')->getStatusCode());

        $web->get('/admin/rbac');
        self::assertSame(403, $web->post('/admin/rbac/sync')->getStatusCode());
        [$dev] = AdminSession::login(['developer']);
        self::assertSame(403, $dev->get('/admin/rbac')->getStatusCode());
    }

    public function testAssignRevokeAndGuardRails(): void
    {
        [$web, $admin] = AdminSession::login(['platform_admin']);
        $target = Fixtures::user(['user']);
        AdminSession::reauth($web, $admin);
        $roles = fn() => (new Query(Fixtures::db()))->select('item_name')->from('yii_rbac_assignment')->where(['user_id' => $target['id']])->column();

        $web->get('/admin/rbac/roles/auditor');
        $web->post('/admin/rbac/roles/auditor/assign', ['username' => $target['username']]);
        self::assertContains('auditor', $roles());
        $web->get('/admin/rbac/roles/auditor?q=' . $target['username']);
        self::assertStringContainsString($target['username'], $web->body());

        $web->post('/admin/rbac/roles/auditor/revoke', ['user_id' => $target['id']]);
        self::assertNotContains('auditor', $roles());
        self::assertContains('user', $roles(), 'other roles are kept');

        // Administrative role by a non-superadmin → refused
        $web->get('/admin/rbac/roles/security_admin');
        $r = $web->post('/admin/rbac/roles/security_admin/assign', ['username' => $target['username']]);
        self::assertSame(403, $r->getStatusCode());
        self::assertNotContains('security_admin', $roles());

        // Self role change → refused
        $web->get('/admin/rbac/roles/auditor');
        $r = $web->post('/admin/rbac/roles/auditor/assign', ['username' => $admin['username']]);
        self::assertSame(403, $r->getStatusCode());

        $audit = (new Query(Fixtures::db()))->select('action')->from('audit_log')->where(['entity_id' => $target['id'], 'action' => 'rbac.roles.changed'])->count();
        self::assertGreaterThanOrEqual(2, (int) $audit);
    }

    public function testSyncRequiresReauthAndIsAudited(): void
    {
        [$web, $admin] = AdminSession::login(['superadmin']);
        self::expireReauth($admin['id']);
        $web->get('/admin/rbac');
        $web->post('/admin/rbac/sync');
        self::assertSame('/auth/confirm', $web->url());
        AdminSession::reauth($web, $admin);
        $web->get('/admin/rbac');
        $web->post('/admin/rbac/sync');
        self::assertSame('/admin/rbac', $web->url());
        self::assertStringContainsString('Catalog synchronized', $web->body());
        self::assertTrue((new Query(Fixtures::db()))->from('audit_log')->where(['action' => 'rbac.catalog.synced', 'actor_id' => $admin['id']])->exists());
    }

    public function testForeignTenantUserCannotBeTargeted(): void
    {
        $db = Fixtures::db();
        $tenant = (string) $db->createCommand("INSERT INTO tenants (code, name) VALUES (:c, 'E2E rbac tenant') RETURNING id", [':c' => 't-' . bin2hex(random_bytes(4))])->queryScalar();
        try {
            $foreign = Fixtures::user(['user'], extra: ['tenant_id' => $tenant]);
            [$web, $admin] = AdminSession::login(['security_admin']); // rbac.manage, no tenants.cross
            AdminSession::reauth($web, $admin);
            $web->get('/admin/rbac/roles/auditor');
            $web->post('/admin/rbac/roles/auditor/assign', ['username' => $foreign['username']]);
            self::assertStringContainsString('User not found.', $web->body());
            self::assertNotContains('auditor', (new Query($db))->select('item_name')->from('yii_rbac_assignment')->where(['user_id' => $foreign['id']])->column());
            $web->get('/admin/rbac/roles/auditor');
            self::assertSame(404, $web->post('/admin/rbac/roles/user/revoke', ['user_id' => $foreign['id']])->getStatusCode());
        } finally {
            Fixtures::cleanup();
            $db->createCommand()->delete('users', ['tenant_id' => $tenant])->execute();
            $db->createCommand()->delete('tenants', ['id' => $tenant])->execute();
        }
    }

    private static function expireReauth(string $userId): void
    {
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $userId])->execute();
    }
}
