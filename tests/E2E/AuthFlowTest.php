<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\Fixtures;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end authentication flows through nginx → PHP-FPM → PostgreSQL/Redis.
 */
final class AuthFlowTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testLoginPageRendersWithSecurityHeaders(): void
    {
        $web = new WebClient();
        $r = $web->get('/login');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('name="_csrf"', $web->body());
        self::assertStringContainsString("default-src 'self'", $r->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $r->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('', $r->getHeaderLine('X-Powered-By'));
        self::assertNotSame('', $r->getHeaderLine('X-Request-Id'));
    }

    public function testInvalidCredentialsShowGenericError(): void
    {
        $web = new WebClient();
        $web->get('/login');
        $r = $web->post('/login', ['login' => 'nobody-' . bin2hex(random_bytes(3)), 'password' => 'wrong-password']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Invalid username or password.', $web->body());
    }

    public function testRegularUserLoginAndLogout(): void
    {
        $u = Fixtures::user(['user']);
        $web = new WebClient();
        $web->get('/login');
        $r = $web->post('/login', ['login' => $u['username'], 'password' => $u['password']]);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('/', $web->url(), 'Non-admin users land on the web home');
        self::assertStringContainsString('Welcome', $web->body());

        // Admin panel is forbidden for a regular user (default deny)
        $r = $web->get('/admin');
        self::assertSame(403, $r->getStatusCode());

        $web->get('/account');
        $r = $web->post('/logout');
        self::assertSame('/login', $web->url());
        $r = $web->get('/account');
        self::assertSame('/login', $web->url(), 'After logout protected pages redirect to login');
    }

    public function testAdminWithMfaFullFlow(): void
    {
        $u = Fixtures::user(['platform_admin'], mfa: true);
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $u['username'], 'password' => $u['password']]);
        self::assertSame('/login/mfa', $web->url());

        $r = $web->post('/login/mfa', ['code' => '000000']);
        self::assertSame(422, $r->getStatusCode());

        $web->post('/login/mfa', ['code' => Fixtures::totp((string) $u['mfa_secret'])]);
        self::assertSame('/admin', $web->url());
        self::assertStringContainsString('Platform Control Center', $web->body());

        // HTMX partial navigation returns only the main region
        $r = $web->htmx('GET', '/admin');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('<main id="main"', ltrim($web->body()));
        self::assertStringNotContainsString('<html', $web->body());
    }

    public function testPrivilegedRoleWithoutMfaIsForcedToEnrol(): void
    {
        $u = Fixtures::user(['security_admin']);
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $u['username'], 'password' => $u['password']]);
        self::assertSame('/auth/mfa/setup', $web->url());

        // Gate: other pages redirect back to enrolment
        $web->get('/admin');
        self::assertSame('/auth/mfa/setup', $web->url());

        preg_match('/Manual key[^<]*<code>([A-Z2-7 ]+)<\/code>/', $web->body(), $m);
        self::assertNotEmpty($m[1] ?? null, 'MFA secret is displayed for manual entry');
        $secret = str_replace(' ', '', $m[1]);
        $web->post('/auth/mfa/setup', ['code' => Fixtures::totp($secret)]);
        self::assertStringContainsString('recovery codes', $web->body());

        $web->get('/admin');
        self::assertSame('/admin', $web->url());
    }

    public function testForcedPasswordChange(): void
    {
        $u = Fixtures::user(['user'], extra: ['must_change_password' => true]);
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $u['username'], 'password' => $u['password']]);
        self::assertSame('/auth/password', $web->url());

        $r = $web->post('/auth/password', ['current_password' => $u['password'], 'new_password' => 'short', 'new_password_confirm' => 'short']);
        self::assertSame(422, $r->getStatusCode());

        $new = 'N3w-Secure-Pass!' . random_int(100, 999);
        $web->post('/auth/password', ['current_password' => $u['password'], 'new_password' => $new, 'new_password_confirm' => $new]);
        self::assertSame('/', $web->url());
    }

    public function testAccountLockoutAfterRepeatedFailures(): void
    {
        $u = Fixtures::user(['user']);
        $web = new WebClient();
        $web->get('/login');
        for ($i = 0; $i < 5; $i++) {
            $web->post('/login', ['login' => $u['username'], 'password' => 'Wrong-password-1!']);
        }
        $r = $web->post('/login', ['login' => $u['username'], 'password' => $u['password']]);
        self::assertSame(429, $r->getStatusCode(), 'Correct password is refused while the account is locked');
        self::assertNotSame('', $r->getHeaderLine('Retry-After'));
    }
}
