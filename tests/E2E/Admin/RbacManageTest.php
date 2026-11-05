<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * Section 04 · dynamic roles & permissions through the admin panel (against the running stack).
 */
final class RbacManageTest extends TestCase
{
    private const ROLE = 'e2e_report_viewer';
    private const PERM = 'e2e_reports.export';

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        $db = Fixtures::db();
        $names = [self::ROLE, self::PERM];
        $db->createCommand()->delete('yii_rbac_item_child', ['or', ['parent' => $names], ['child' => $names]])->execute();
        $db->createCommand()->delete('yii_rbac_assignment', ['item_name' => $names])->execute();
        $db->createCommand()->delete('yii_rbac_item', ['name' => $names])->execute();
        $db->createCommand()->delete('rbac_role_meta', ['name' => $names])->execute();
        Fixtures::cleanup();
    }

    public function testCreateRoleAttachPermissionAssignAndDelete(): void
    {
        [$web, $admin] = AdminSession::login(['superadmin']);
        $member = Fixtures::user(['user']);
        AdminSession::reauth($web, $admin);
        $db = Fixtures::db();

        $web->get('/admin/rbac/permissions');
        self::assertStringContainsString('New permission', $web->body());
        $web->post('/admin/rbac/permissions', ['name' => self::PERM, 'description' => 'Export reports (e2e)']);
        self::assertSame('/admin/rbac/permissions', $web->url());
        self::assertStringContainsString(self::PERM, $web->body());

        $web->get('/admin/rbac');
        $web->post('/admin/rbac/roles', ['name' => self::ROLE, 'description' => 'E2E role', 'clone_from' => 'user']);
        self::assertSame('/admin/rbac/roles/' . self::ROLE, $web->url());
        self::assertStringContainsString('Edit role', $web->body());

        $web->post('/admin/rbac/roles/' . self::ROLE . '/edit', [
            'description' => 'E2E role (edited)',
            'permissions' => ['profile.self', self::PERM],
            'children' => [],
        ]);
        $links = (new Query($db))->select('child')->from('yii_rbac_item_child')->where(['parent' => self::ROLE])->column();
        sort($links);
        self::assertSame([self::PERM, 'profile.self'], $links);

        $web->post('/admin/rbac/roles/' . self::ROLE . '/assign', ['username' => $member['username']]);
        $assigned = (new Query($db))->from('yii_rbac_assignment')->where(['item_name' => self::ROLE, 'user_id' => $member['id']])->count();
        self::assertSame(1, (int) $assigned);

        $web->get('/admin/rbac/matrix');
        self::assertStringContainsString(self::ROLE, $web->body());

        // system role cannot be deleted; custom role can
        $web->get('/admin/rbac/roles/auditor');
        $web->post('/admin/rbac/roles/auditor/delete');
        self::assertStringContainsString('cannot be deleted', $web->body());
        self::assertSame(1, (int) (new Query($db))->from('yii_rbac_item')->where(['name' => 'auditor'])->count());
        $web->get('/admin/rbac/roles/' . self::ROLE);
        $web->post('/admin/rbac/roles/' . self::ROLE . '/delete');
        self::assertSame('/admin/rbac', $web->url());
        self::assertSame(0, (int) (new Query($db))->from('yii_rbac_item')->where(['name' => self::ROLE])->count());
        self::assertSame(0, (int) (new Query($db))->from('yii_rbac_assignment')->where(['item_name' => self::ROLE])->count());

        $audit = (new Query($db))->select('action')->from('audit_log')->where(['entity_id' => [self::ROLE, self::PERM]])->column();
        self::assertContains('rbac.role.created', $audit);
        self::assertContains('rbac.role.links_changed', $audit);
        self::assertContains('rbac.role.deleted', $audit);
        self::assertContains('rbac.permission.created', $audit);
    }

    public function testLowerRolesCannotManage(): void
    {
        [$web] = AdminSession::login(['auditor']);   // rbac.view only
        self::assertSame(200, $web->get('/admin/rbac/permissions')->getStatusCode());
        self::assertStringNotContainsString('New permission', $web->body());
        self::assertSame(403, $web->post('/admin/rbac/roles', ['name' => self::ROLE, 'description' => 'x'])->getStatusCode());
        self::assertSame(403, $web->post('/admin/rbac/permissions', ['name' => self::PERM, 'description' => 'x'])->getStatusCode());
        self::assertSame(403, $web->post('/admin/rbac/roles/auditor/edit', ['permissions' => ['users.manage']])->getStatusCode());

        [$dev] = AdminSession::login(['developer']);  // no rbac.view
        self::assertSame(403, $dev->get('/admin/rbac/permissions')->getStatusCode());

        // platform_admin has rbac.manage but cannot grant privileged permissions
        [$pa, $paUser] = AdminSession::login(['platform_admin']);
        AdminSession::reauth($pa, $paUser);
        $pa->get('/admin/rbac');
        $pa->post('/admin/rbac/roles', ['name' => self::ROLE, 'description' => 'by platform admin']);
        $r = $pa->post('/admin/rbac/roles/' . self::ROLE . '/edit', ['permissions' => ['settings.manage']]);
        self::assertSame(403, $r->getStatusCode());
        $links = (new Query(Fixtures::db()))->select('child')->from('yii_rbac_item_child')->where(['parent' => self::ROLE])->column();
        self::assertNotContains('settings.manage', $links);
    }
}
