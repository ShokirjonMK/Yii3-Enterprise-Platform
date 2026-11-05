<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Access\AccessService;
use App\Platform\Access\RbacSynchronizer;
use App\Platform\Access\RoleManager;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;
use Yiisoft\Rbac\ItemsStorageInterface;

/**
 * Dynamic RBAC: custom roles/permissions, link editing, guard rails, sync preserving admin-panel overrides.
 */
final class DynamicRbacTest extends IntegrationTestCase
{
    private const ROLES = ['t_report_viewer', 't_report_copy', 't_child_a', 't_child_b', 't_escalate'];
    private const PERMS = ['t_reports.export', 't_reports.view'];

    protected function tearDown(): void
    {
        $this->cleanupItems();
        parent::tearDown();
    }

    private function cleanupItems(): void
    {
        $db = Fixtures::db();
        $names = [...self::ROLES, ...self::PERMS];
        $db->createCommand()->delete('yii_rbac_item_child', ['or', ['parent' => $names], ['child' => $names]])->execute();
        $db->createCommand()->delete('yii_rbac_assignment', ['item_name' => $names])->execute();
        $db->createCommand()->delete('yii_rbac_item', ['name' => $names])->execute();
        $db->createCommand()->delete('rbac_role_meta', ['name' => $names])->execute();
        $db->createCommand()->delete('rbac_link_overrides', ['or', ['parent' => $names], ['child' => $names]])->execute();
        $db->createCommand()->delete('rbac_link_overrides', ['parent' => 'auditor'])->execute();
        $this->get(RbacSynchronizer::class)->sync();
    }

    public function testRoleLifecycleWithPermissionsAndAssignments(): void
    {
        $roles = $this->get(RoleManager::class);
        $access = $this->get(AccessService::class);
        $super = Fixtures::user(['superadmin']);
        $member = Fixtures::user(['user']);

        $roles->createPermission($super['id'], 't_reports.export', 'Export reports');
        self::assertTrue($access->can($super['id'], 't_reports.export'), 'superadmin receives every new permission');

        $roles->createRole($super['id'], 't_report_viewer', 'Reads reports');
        $roles->updateRole($super['id'], 't_report_viewer', ['t_reports.export', 'audit.view'], ['user'], 'Reads and exports reports');
        $r = $roles->role('t_report_viewer');
        self::assertFalse($r['system']);
        self::assertSame(['audit.view', 't_reports.export'], $r['permissions']);
        self::assertSame(['user'], $r['children']);
        self::assertSame('Reads and exports reports', $r['description']);
        self::assertContains('profile.self', $roles->effective('t_report_viewer'), 'inherited from child role');
        self::assertFalse($access->isAdminRole('t_report_viewer'));

        $access->setRoles($super['id'], $member['id'], ['user', 't_report_viewer']);
        self::assertTrue($access->can($member['id'], 't_reports.export'));

        // detach → the permission is gone at once
        $roles->updateRole($super['id'], 't_report_viewer', ['audit.view'], ['user']);
        self::assertFalse($access->can($member['id'], 't_reports.export'));

        // clone copies direct permissions and children
        $roles->createRole($super['id'], 't_report_copy', 'Copy', cloneFrom: 't_report_viewer');
        self::assertSame(['audit.view'], $roles->role('t_report_copy')['permissions']);
        self::assertSame(['user'], $roles->role('t_report_copy')['children']);

        // delete custom role → assignments removed, audited
        self::assertSame(1, $roles->deleteRole($super['id'], 't_report_viewer'));
        self::assertNull($this->get(ItemsStorageInterface::class)->getRole('t_report_viewer'));
        self::assertNotContains('t_report_viewer', $access->rolesOf($member['id']));
        $actions = (new Query($this->db))->select('action')->from('audit_log')->where(['entity_id' => 't_report_viewer'])->column();
        foreach (['rbac.role.created', 'rbac.role.links_changed', 'rbac.role.updated', 'rbac.role.deleted'] as $a) {
            self::assertContains($a, $actions, "audited: $a");
        }

        // custom permission lifecycle
        $roles->deletePermission($super['id'], 't_reports.export');
        self::assertNull($this->get(ItemsStorageInterface::class)->getPermission('t_reports.export'));
    }

    public function testSystemItemsAndSuperadminAreProtected(): void
    {
        $roles = $this->get(RoleManager::class);
        $super = Fixtures::user(['superadmin']);
        foreach ([
            fn() => $roles->deleteRole($super['id'], 'auditor'),
            fn() => $roles->deletePermission($super['id'], 'users.view'),
            fn() => $roles->updateRole($super['id'], 'superadmin', [], []),
        ] as $call) {
            try {
                $call();
                self::fail('must be refused');
            } catch (ConflictException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertNotNull($this->get(ItemsStorageInterface::class)->getRole('auditor'));
        try {
            $roles->createRole($super['id'], 'Bad Name', '');
            self::fail('invalid name');
        } catch (ValidationException) {
        }
        try {
            $roles->createRole($super['id'], 'auditor', '');
            self::fail('duplicate');
        } catch (ValidationException) {
        }
    }

    public function testInheritanceCycleIsRefused(): void
    {
        $roles = $this->get(RoleManager::class);
        $super = Fixtures::user(['superadmin']);
        $roles->createRole($super['id'], 't_child_a', '');
        $roles->createRole($super['id'], 't_child_b', '');
        $roles->updateRole($super['id'], 't_child_a', [], ['t_child_b']);
        try {
            $roles->updateRole($super['id'], 't_child_b', [], ['t_child_a']);
            self::fail('cycle must be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('cycle', $e->getMessage());
        }
    }

    public function testNonSuperadminCannotEscalate(): void
    {
        $roles = $this->get(RoleManager::class);
        $access = $this->get(AccessService::class);
        $super = Fixtures::user(['superadmin']);
        $pa = Fixtures::user(['platform_admin']);   // has rbac.manage, not superadmin

        $roles->createRole($pa['id'], 't_escalate', 'Created by platform admin');
        // allowed: a permission the actor holds
        $roles->updateRole($pa['id'], 't_escalate', ['users.view'], []);
        $refused = [
            'privileged permission' => fn() => $roles->updateRole($pa['id'], 't_escalate', ['users.view', 'settings.manage'], []),
            'permission not held' => fn() => $roles->updateRole($pa['id'], 't_escalate', ['db.view', 'devtools.use'], []),
            'admin child role' => fn() => $roles->updateRole($pa['id'], 't_escalate', [], ['security_admin']),
            'admin flag' => fn() => $roles->updateRole($pa['id'], 't_escalate', ['users.view'], [], null, true),
            'edit admin role' => fn() => $roles->updateRole($pa['id'], 'devops_sre', [], ['auditor']),
            'create admin role' => fn() => $roles->createRole($pa['id'], 't_report_copy', '', true),
        ];
        foreach ($refused as $case => $call) {
            try {
                $call();
                self::fail("must be refused: $case");
            } catch (AccessDeniedException) {
            }
        }
        self::assertSame(['users.view'], $roles->role('t_escalate')['permissions']);

        // superadmin may make it administrative → afterwards the platform admin can no longer assign it
        $roles->updateRole($super['id'], 't_escalate', ['users.view', 'settings.manage'], []);
        self::assertTrue($access->isAdminRole('t_escalate'));
        $target = Fixtures::user(['user']);
        try {
            $access->setRoles($pa['id'], $target['id'], ['user', 't_escalate']);
            self::fail('non-superadmin cannot grant a role with privileged permissions');
        } catch (AccessDeniedException) {
        }
        try {
            $roles->deleteRole($pa['id'], 't_escalate');
            self::fail('non-superadmin cannot delete an admin role');
        } catch (AccessDeniedException) {
        }
    }

    public function testSyncPreservesOverridesAndResetRestoresCatalog(): void
    {
        $roles = $this->get(RoleManager::class);
        $sync = $this->get(RbacSynchronizer::class);
        $super = Fixtures::user(['superadmin']);
        $roles->createPermission($super['id'], 't_reports.view', 'View reports');

        [$before] = [$roles->role('auditor')['permissions']];
        $wanted = array_values(array_diff([...$before, 't_reports.view'], ['incidents.view']));
        $roles->updateRole($super['id'], 'auditor', $wanted, $roles->role('auditor')['children']);

        $sync->sync();
        $after = $roles->role('auditor')['permissions'];
        self::assertContains('t_reports.view', $after, 'added link survives sync');
        self::assertNotContains('incidents.view', $after, 'removed link stays removed after sync');
        self::assertCount(2, $roles->overridesOf('auditor'));
        self::assertContains('t_reports.view', $roles->effective('superadmin'), 'superadmin keeps custom permissions after sync');

        self::assertSame(2, $roles->resetRole($super['id'], 'auditor', $sync));
        $reset = $roles->role('auditor')['permissions'];
        self::assertContains('incidents.view', $reset);
        self::assertNotContains('t_reports.view', $reset);
        self::assertSame([], $roles->overridesOf('auditor'));
    }
}
