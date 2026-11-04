<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * Section 02 · Users & Organizations through nginx → PHP-FPM → PostgreSQL (running dev stack).
 */
final class UsersAdminTest extends TestCase
{
    /** @var list<string> */
    private array $tenants = [];
    /** @var list<string> users created through the UI (archived in tearDown) */
    private array $uiUsers = [];

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
        $db = Fixtures::db();
        foreach ($this->uiUsers as $id) {
            $db->createCommand()->update('users', ['status' => 'archived', 'deleted_at' => new \Yiisoft\Db\Expression\Expression('now()')], ['id' => $id])->execute();
            $db->createCommand()->delete('yii_rbac_assignment', ['user_id' => $id])->execute();
        }
        foreach ($this->tenants as $t) {
            $db->createCommand()->delete('users', ['tenant_id' => $t])->execute();

            $db->createCommand()->delete('tenants', ['id' => $t])->execute();
        }
        $db->createCommand("DELETE FROM organizations WHERE code LIKE 'e2e-%' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.organization_id = organizations.id) AND NOT EXISTS (SELECT 1 FROM organizations c WHERE c.parent_id = organizations.id)")->execute();
        $db->createCommand("DELETE FROM organizations WHERE code LIKE 'e2e-%' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.organization_id = organizations.id)")->execute();
    }

    public function testListSearchSortAndHtmxPartial(): void
    {
        [$web] = AdminSession::login(['superadmin']);
        $target = Fixtures::user(['user']);
        $r = $web->get('/admin/users?q=' . $target['username'] . '&sort=-created_at');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString($target['username'], $web->body());
        self::assertStringContainsString('hx-push-url="true"', $web->body());

        $r = $web->htmx('GET', '/admin/users?q=' . $target['username'] . '&sort=bogus;drop');
        self::assertSame(200, $r->getStatusCode(), 'unknown sort keys fall back to the whitelist default');
        self::assertStringStartsWith('<main id="main"', ltrim($web->body()));
        self::assertStringContainsString($target['username'], $web->body());

        $r = $web->get('/admin/users/' . $target['id']);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Active sessions', $web->body());
        self::assertStringContainsString('Linked SSO identities', $web->body());
        self::assertStringNotContainsString(' style="', $web->body(), 'strict CSP: no inline styles');
    }

    public function testCreateUserShowsTemporaryPasswordOnceAndStoresOnlyHash(): void
    {
        [$web] = AdminSession::login(['superadmin']);
        $web->get('/admin/users/create');
        $username = 't-' . bin2hex(random_bytes(5));
        $r = $web->post('/admin/users/create', ['username' => $username, 'full_name' => 'E2E Created', 'email' => $username . '@test.local', 'auth_policy' => 'any', 'locale' => 'uz']);
        self::assertSame(201, $r->getStatusCode(), $web->body());
        self::assertStringContainsString('no-store', $r->getHeaderLine('Cache-Control'));
        self::assertMatchesRegularExpression('/data-temporary-password>([^<]+)</', $web->body());
        preg_match('/data-temporary-password>([^<]+)</', $web->body(), $m);
        $password = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);

        $row = (new Query(Fixtures::db()))->from('users')->where(['username' => $username])->one();
        self::assertNotNull($row);
        $this->trackUser((string) $row['id']);
        self::assertNotSame($password, $row['password_hash']);
        self::assertStringStartsWith('$argon2id$', (string) $row['password_hash']);
        self::assertTrue(password_verify($password, (string) $row['password_hash']));
        self::assertTrue((bool) $row['must_change_password']);

        self::assertSame(200, $web->get('/admin/users/' . $row['id'])->getStatusCode());
        self::assertStringNotContainsString($password, $web->body(), 'the temporary password is never shown again');
    }

    public function testValidationErrorsRerenderFormWith422(): void
    {
        [$web] = AdminSession::login(['superadmin']);
        $web->get('/admin/users/create');
        $r = $web->post('/admin/users/create', ['username' => 'x', 'email' => 'not-an-email']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('field__error', $web->body());
        self::assertStringContainsString('Invalid e-mail address.', $web->body());
    }

    public function testBlockUnblockUnlockResetPasswordWithReauthAndArchive(): void
    {
        [$web, $admin] = AdminSession::login(['superadmin']);
        $target = Fixtures::user(['user']);
        $db = Fixtures::db();
        $web->get('/admin/users/' . $target['id']);

        $web->post('/admin/users/' . $target['id'] . '/block');
        self::assertSame('blocked', $db->createCommand('SELECT status FROM users WHERE id = :id', [':id' => $target['id']])->queryScalar());
        $web->post('/admin/users/' . $target['id'] . '/unblock');
        self::assertSame('active', $db->createCommand('SELECT status FROM users WHERE id = :id', [':id' => $target['id']])->queryScalar());

        $db->createCommand()->update('users', ['failed_logins' => 7, 'locked_until' => (new \DateTimeImmutable('+1 hour'))->format(DATE_ATOM)], ['id' => $target['id']])->execute();
        $web->post('/admin/users/' . $target['id'] . '/unlock');
        self::assertSame(0, (int) $db->createCommand('SELECT failed_logins FROM users WHERE id = :id', [':id' => $target['id']])->queryScalar());

        // Critical: password reset requires step-up re-authentication
        self::expireReauth($admin['id']);
        $web->post('/admin/users/' . $target['id'] . '/reset-password');
        self::assertSame('/auth/confirm', $web->url());
        AdminSession::reauth($web, $admin);
        $web->get('/admin/users/' . $target['id']);
        $r = $web->post('/admin/users/' . $target['id'] . '/reset-password');
        self::assertSame(200, $r->getStatusCode());
        preg_match('/data-temporary-password>([^<]+)</', $web->body(), $m);
        self::assertNotEmpty($m[1] ?? null);
        $hash = (string) $db->createCommand('SELECT password_hash FROM users WHERE id = :id', [':id' => $target['id']])->queryScalar();
        self::assertTrue(password_verify(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), $hash));

        $web->get('/admin/users/' . $target['id']);
        $web->post('/admin/users/' . $target['id'] . '/archive');
        self::assertSame('archived', $db->createCommand('SELECT status FROM users WHERE id = :id', [':id' => $target['id']])->queryScalar());
        self::assertSame(404, $web->get('/admin/users/' . $target['id'])->getStatusCode());

        $actions = (new Query($db))->select('action')->from('audit_log')->where(['entity_id' => $target['id']])->column();
        foreach (['user.updated', 'user.unlocked', 'user.password.reset', 'user.archived'] as $a) {
            self::assertContains($a, $actions, "audit record $a");
        }
    }

    public function testLowerRolesAreDenied(): void
    {
        [$web] = AdminSession::login(['auditor']);
        $target = Fixtures::user(['user']);
        self::assertSame(200, $web->get('/admin/users')->getStatusCode(), 'auditor may view');
        self::assertSame(403, $web->get('/admin/users/create')->getStatusCode());
        $web->get('/admin/users');
        self::assertSame(403, $web->post('/admin/users/' . $target['id'] . '/block')->getStatusCode());
        self::assertSame('active', Fixtures::db()->createCommand('SELECT status FROM users WHERE id = :id', [':id' => $target['id']])->queryScalar());
        self::assertSame(403, $web->get('/admin/organizations/create')->getStatusCode());

        [$dev] = AdminSession::login(['developer']);
        self::assertSame(403, $dev->get('/admin/users')->getStatusCode(), 'developer has no users.view');
    }

    public function testForeignTenantUserIsNotFoundForTenantScopedAdmin(): void
    {
        $foreignTenant = $this->tenant();
        $foreign = Fixtures::user(['user'], extra: ['tenant_id' => $foreignTenant]);
        $local = Fixtures::user(['user']);
        [$web] = AdminSession::login(['business_admin']); // users.manage, no tenants.cross

        self::assertSame(404, $web->get('/admin/users/' . $foreign['id'])->getStatusCode());
        self::assertSame(404, $web->get('/admin/users/' . $foreign['id'] . '/edit')->getStatusCode());
        $web->get('/admin/users/' . $local['id']);
        self::assertSame(404, $web->post('/admin/users/' . $foreign['id'] . '/block')->getStatusCode());
        self::assertSame('active', Fixtures::db()->createCommand('SELECT status FROM users WHERE id = :id', [':id' => $foreign['id']])->queryScalar());

        $web->get('/admin/users?q=' . $foreign['username']);
        self::assertStringNotContainsString('/admin/users/' . $foreign['id'], $web->body());
        self::assertStringContainsString('0 users match', $web->body());
        $web->get('/admin/users?q=' . $local['username']);
        self::assertStringContainsString('/admin/users/' . $local['id'], $web->body());

        // business_admin cannot touch an administrative account of its own tenant
        $privileged = Fixtures::user(['platform_admin']);
        self::assertSame(403, $web->post('/admin/users/' . $privileged['id'] . '/block')->getStatusCode());
    }

    public function testCsrfTokenIsRequired(): void
    {
        [$web] = AdminSession::login(['superadmin']);
        $target = Fixtures::user(['user']);
        $web->get('/admin/users/' . $target['id']);
        $r = $web->post('/admin/users/' . $target['id'] . '/block', [], [], false);
        self::assertContains($r->getStatusCode(), [400, 403, 422]);
        self::assertSame('active', Fixtures::db()->createCommand('SELECT status FROM users WHERE id = :id', [':id' => $target['id']])->queryScalar());
    }

    public function testAdminRoleGrantByNonSuperadminIsRefused(): void
    {
        [$web, $admin] = AdminSession::login(['platform_admin']);
        $target = Fixtures::user(['user']);
        AdminSession::reauth($web, $admin);
        $web->get('/admin/users/' . $target['id']);
        $r = $web->post('/admin/users/' . $target['id'] . '/roles', ['roles' => ['user', 'superadmin']]);
        self::assertSame(403, $r->getStatusCode());
        $roles = (new Query(Fixtures::db()))->select('item_name')->from('yii_rbac_assignment')->where(['user_id' => $target['id']])->column();
        self::assertNotContains('superadmin', $roles);

        $web->get('/admin/users/' . $target['id']);
        $web->post('/admin/users/' . $target['id'] . '/roles', ['roles' => ['user', 'auditor']]);
        $roles = (new Query(Fixtures::db()))->select('item_name')->from('yii_rbac_assignment')->where(['user_id' => $target['id']])->column();
        self::assertContains('auditor', $roles, 'non-administrative roles can be granted by rbac.manage');
    }

    public function testSessionRevocation(): void
    {
        [$web] = AdminSession::login(['superadmin']);
        [$victimWeb, $victim] = AdminSession::login(['auditor']);
        $hash = (string) Fixtures::db()->createCommand('SELECT id FROM user_sessions WHERE user_id = :u AND revoked_at IS NULL LIMIT 1', [':u' => $victim['id']])->queryScalar();
        self::assertNotSame('', $hash);

        $web->get('/admin/users/' . $victim['id']);
        self::assertStringContainsString($hash, $web->body());
        $web->post('/admin/users/' . $victim['id'] . '/sessions/' . $hash . '/revoke');
        self::assertNotNull(Fixtures::db()->createCommand('SELECT revoked_at FROM user_sessions WHERE id = :id', [':id' => $hash])->queryScalar());

        $victimWeb->get('/admin');
        self::assertSame('/login', $victimWeb->url(), 'revoked session is logged out');
    }

    public function testOrganizationTreeCrudAndCyclePrevention(): void
    {
        [$web] = AdminSession::login(['superadmin']);
        $tenant = (string) Fixtures::db()->createCommand("SELECT id FROM tenants WHERE code = 'default'")->queryScalar();
        $suffix = bin2hex(random_bytes(3));
        $web->get('/admin/organizations/create?tenant_id=' . $tenant);
        $web->post('/admin/organizations/create', ['tenant_id' => $tenant, 'code' => "e2e-root-$suffix", 'name' => 'E2E Root', 'type' => 'company', 'status' => 'active', 'parent_id' => '']);
        $root = (string) Fixtures::db()->createCommand('SELECT id FROM organizations WHERE code = :c', [':c' => "e2e-root-$suffix"])->queryScalar();
        self::assertNotSame('', $root);
        $web->get('/admin/organizations/create?tenant_id=' . $tenant);
        $web->post('/admin/organizations/create', ['tenant_id' => $tenant, 'code' => "e2e-child-$suffix", 'name' => 'E2E Child', 'type' => 'department', 'status' => 'active', 'parent_id' => $root]);
        $child = (string) Fixtures::db()->createCommand('SELECT id FROM organizations WHERE code = :c', [':c' => "e2e-child-$suffix"])->queryScalar();
        self::assertNotSame('', $child);

        $r = $web->get('/admin/organizations?tenant_id=' . $tenant);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('E2E Child', $web->body());
        self::assertStringContainsString('id-tree--1', $web->body());

        // Moving the root under its own child would create a cycle
        $web->get("/admin/organizations/$root/edit");
        $r = $web->post("/admin/organizations/$root/edit", ['code' => "e2e-root-$suffix", 'name' => 'E2E Root', 'type' => 'company', 'status' => 'active', 'parent_id' => $child]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('cannot be moved under itself', $web->body());

        // A parent with children cannot be deleted
        $web->get('/admin/organizations?tenant_id=' . $tenant);
        $web->post("/admin/organizations/$root/delete");
        self::assertSame(1, (int) Fixtures::db()->createCommand('SELECT count(*) FROM organizations WHERE id = :id', [':id' => $root])->queryScalar());
        $web->post("/admin/organizations/$child/delete");
        $web->post("/admin/organizations/$root/delete");
        self::assertSame(0, (int) Fixtures::db()->createCommand('SELECT count(*) FROM organizations WHERE id IN (:a, :b)', [':a' => $root, ':b' => $child])->queryScalar());
    }

    private function tenant(): string
    {
        $id = (string) Fixtures::db()->createCommand("INSERT INTO tenants (code, name) VALUES (:c, 'E2E foreign tenant') RETURNING id", [':c' => 't-' . bin2hex(random_bytes(4))])->queryScalar();
        $this->tenants[] = $id;
        return $id;
    }

    private function trackUser(string $id): void
    {
        $this->uiUsers[] = $id;
    }

    private static function expireReauth(string $userId): void
    {
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $userId])->execute();
    }
}
