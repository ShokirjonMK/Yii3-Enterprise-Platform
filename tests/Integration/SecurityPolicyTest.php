<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Identity\Application\LoginThrottle;
use App\Platform\Identity\Application\SecurityPolicy;
use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Identity\Mfa\MfaPolicyService;
use App\Platform\Settings\SettingsService;
use App\Tests\Support\Fixtures;

/**
 * Admin-managed session / sign-in limits are really applied at runtime (before: settings existed but code used fixed
 * params), and the 2FA policy explains why 2FA is mandatory.
 */
final class SecurityPolicyTest extends IntegrationTestCase
{
    private const KEYS = ['security.session_idle_minutes', 'security.session_absolute_hours', 'security.reauth_minutes', 'security.login_max_attempts', 'security.lockout_minutes'];

    private string $admin = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Fixtures::user(['superadmin'])['id'];
    }

    protected function tearDown(): void
    {
        $s = $this->get(SettingsService::class);
        foreach (self::KEYS as $k) {
            try {
                $s->reset($this->admin, $k);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    public function testSettingsDriveTimeoutsAndLockout(): void
    {
        $p = $this->get(SecurityPolicy::class);
        self::assertSame(1800, $p->sessionIdleSeconds(), 'default 30 min');
        self::assertSame(43200, $p->sessionAbsoluteSeconds(), 'default 12 h');
        self::assertSame(300, $p->reauthWindowSeconds());

        $s = $this->get(SettingsService::class);
        $s->set($this->admin, 'security.session_idle_minutes', 240);
        $s->set($this->admin, 'security.session_absolute_hours', 48);
        $s->set($this->admin, 'security.reauth_minutes', 30);
        $s->set($this->admin, 'security.login_max_attempts', 3);
        $s->set($this->admin, 'security.lockout_minutes', 2);
        self::assertSame(14400, $p->sessionIdleSeconds());
        self::assertSame(172800, $p->sessionAbsoluteSeconds());
        self::assertSame(1800, $p->reauthWindowSeconds());

        // SessionRegistry: a session idle for 2 h is still valid with the 4 h setting; expiry = 48 h
        $user = Fixtures::user(['user']);
        $reg = $this->get(SessionRegistry::class);
        $sid = bin2hex(random_bytes(16));
        $reg->start($sid, $user['id'], 'password', '10.0.0.1', 'test', true);
        $this->db->createCommand("UPDATE user_sessions SET last_seen_at = now() - interval '2 hours' WHERE id = :id", [':id' => SessionRegistry::hash($sid)])->execute();
        self::assertTrue($reg->validate($sid, $user['id'])['valid']);
        $expires = (string) $this->db->createCommand('SELECT expires_at FROM user_sessions WHERE id = :id', [':id' => SessionRegistry::hash($sid)])->queryScalar();
        self::assertGreaterThan(time() + 47 * 3600, strtotime($expires));
        $s->set($this->admin, 'security.session_idle_minutes', 60);
        $v = $reg->validate($sid, $user['id']);
        self::assertSame([false, 'idle_timeout'], [$v['valid'], $v['reason']], 'a shorter setting applies immediately');

        // LoginThrottle: lockout after 3 failures, for 2 minutes
        $t = $this->get(LoginThrottle::class);
        self::assertFalse($t->registerFailure($user['id']));
        self::assertFalse($t->registerFailure($user['id']));
        self::assertTrue($t->registerFailure($user['id']), 'locked at the 3rd failure');
        $until = strtotime((string) $this->db->createCommand('SELECT locked_until FROM users WHERE id = :id', [':id' => $user['id']])->queryScalar());
        self::assertLessThanOrEqual(time() + 125, $until);
        self::assertGreaterThan(time() + 60, $until);
    }

    public function testMfaPolicyExplainsWhyItIsMandatory(): void
    {
        $users = $this->get(UserRepository::class);
        $svc = $this->get(MfaPolicyService::class);
        $super = $users->find(Fixtures::user(['superadmin'], extra: ['mfa_required' => true])['id']);
        $p = $svc->forUser($super);
        self::assertTrue($p->required);
        self::assertContains('account', $p->requiredBy);
        self::assertContains('superadmin', $p->requiredBy, 'seeded policy of the superadmin role');

        $plain = $users->find(Fixtures::user(['user'])['id']);
        self::assertSame([], $svc->forUser($plain)->requiredBy);
    }

    public function testGeneralAdminRole(): void
    {
        $access = $this->get(\App\Platform\Access\AccessService::class);
        self::assertTrue($access->isAdminRole('admin'), 'only a superadmin may grant it');

        $admin = Fixtures::user(['admin']);
        $perms = $access->permissionsOf($admin['id']);
        foreach (['admin.access', 'users.manage', 'auth.manage', 'rbac.manage', 'settings.manage', 'security.manage', 'db.manage', 'deploy.approve', 'devtools.use', 'audit.export'] as $p) {
            self::assertContains($p, $perms);
        }
        self::assertNotContains('rbac.grant_admin', $perms, 'break-glass stays with superadmin');
        self::assertNotContains('users.impersonate', $perms);

        // 2FA follows the general "*" policy (optional), unlike superadmin
        $p = $this->get(MfaPolicyService::class)->forUser($this->get(UserRepository::class)->find($admin['id']));
        self::assertFalse($p->required);
        self::assertSame([], $p->requiredBy);
    }
}
