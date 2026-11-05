<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;

/**
 * Login history against the running stack: a user sees the own successful and failed sign-ins (with the second
 * factor) in Account → Login history; administrators with users.login_history see everybody in Admin → Users →
 * Login history; administrators without it and regular users are refused.
 */
final class LoginHistoryFlowTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testUserSeesOwnHistoryAndAdminsSeeEverybody(): void
    {
        $u = Fixtures::user(['user'], mfa: true);
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $u['username'], 'password' => 'Wrong-password-1']);   // failed attempt
        $web->post('/login', ['login' => $u['username'], 'password' => $u['password']]);
        $web->post('/login/mfa', ['code' => Fixtures::totp((string) $u['mfa_secret'])]);
        self::assertStringNotContainsString('/login', $web->url());

        $r = $web->get('/account/login-history');
        self::assertSame(200, $r->getStatusCode());
        $body = $web->body();
        self::assertStringContainsString('Login history', $body);
        self::assertStringContainsString('Wrong password', $body, 'failed attempt with its reason');
        self::assertStringContainsString('Authenticator app', $body, 'second factor of the successful sign-in');
        self::assertSame(2, substr_count($body, '<tr>') - 1, 'exactly the two own attempts (header row excluded)');
        $web->get('/account/login-history?result=failure');
        self::assertSame(1, substr_count($web->body(), '<tr>') - 1);
        self::assertStringNotContainsString('href="/admin/users/login-history"', $body, 'no "all users" link for a regular user');
        self::assertSame(403, $web->get('/admin/users/login-history')->getStatusCode());

        // administrators: superadmin sees everybody, filtered by the user
        [$admin] = AdminSession::login(['superadmin']);
        $r = $admin->get('/admin/users/login-history?user=' . $u['id']);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString($u['username'], $admin->body());
        self::assertStringContainsString('Wrong password', $admin->body());
        $admin->get('/admin/users/' . $u['id']);
        self::assertStringContainsString('/admin/users/login-history?user=' . $u['id'], $admin->body(), 'link from the user page');
        $admin->get('/admin/users');
        self::assertStringContainsString('href="/admin/users/login-history"', $admin->body(), 'tab in Users & Organizations');

        // an administrator without users.login_history is refused (route + service)
        [$business] = AdminSession::login(['business_admin']);
        self::assertSame(403, $business->get('/admin/users/login-history')->getStatusCode());
        self::assertSame(200, $business->get('/account/login-history')->getStatusCode(), 'own history is always visible');
    }
}
