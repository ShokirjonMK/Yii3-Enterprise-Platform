<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Platform\Settings\SettingsService;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;

/**
 * Admin → Authentication → Sessions & sign-in against the running stack, and the explanation on Account → Security
 * of why two-factor authentication cannot be switched off (account flag / role policy, with links for admins).
 */
final class SessionSettingsFlowTest extends TestCase
{
    private ?string $admin = null;

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null) {
            try {
                TestContainer::service(SettingsService::class)->reset($this->admin, 'security.session_idle_minutes', permission: 'auth.manage');
            } catch (\Throwable) {
            }
        }
        Fixtures::cleanup();
    }

    public function testManageSessionLimitsAndSeeWhy2faIsMandatory(): void
    {
        [$web, $user] = AdminSession::login(['superadmin']);
        $this->admin = $user['id'];
        $r = $web->get('/admin/auth/sessions');
        self::assertSame(200, $r->getStatusCode());
        foreach (['Sign out after inactivity (minutes)', 'Maximum session length (hours)', 'Step-up confirmation valid for (minutes)', 'Failed logins before lockout'] as $label) {
            self::assertStringContainsString($label, $web->body());
        }

        AdminSession::reauth($web, $user);
        $web->post('/admin/auth/sessions', ['security_session_idle_minutes' => '240']);
        self::assertSame('/admin/auth/sessions', $web->url());
        self::assertStringContainsString('value="240"', $web->body());
        self::assertSame(240, (int) TestContainer::service(SettingsService::class)->get('security.session_idle_minutes'));

        // the seeded superadmin role policy requires 2FA: the security page says why and links to the policy
        $web->get('/account/security');
        self::assertStringContainsString('cannot be switched off here', $web->body());
        self::assertStringContainsString('superadmin', $web->body());
        self::assertStringContainsString('/admin/auth/mfa#policies', $web->body());

        // a regular user cannot open the page
        [$plain] = AdminSession::login(['user']);
        self::assertSame(403, $plain->get('/admin/auth/sessions')->getStatusCode());
    }
}
