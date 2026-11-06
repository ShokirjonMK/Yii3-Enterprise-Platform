<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;

/**
 * The API documentation is private: /api/docs and /api/v1/openapi.json open only for a signed-in user with
 * api.docs.view or a browser unlocked with a superadmin-issued access password (shown once, revocable).
 * The generated document is no longer a public static file.
 */
final class ApiDocsAccessTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        try {
            Fixtures::db()->createCommand("DELETE FROM api_docs_passwords WHERE label LIKE 'e2e-%'")->execute();
        } catch (\Throwable) {
        }
        Fixtures::resetLoginThrottle();
        Fixtures::cleanup();
    }

    public function testGuestIsLockedOut(): void
    {
        $guest = new WebClient();
        self::assertSame(401, $guest->get('/api/docs')->getStatusCode());
        self::assertStringContainsString('name="password"', $guest->body());
        self::assertStringNotContainsString('swagger-ui-bundle.js', $guest->body());
        self::assertSame(401, $guest->get('/api/v1/openapi.json')->getStatusCode());
        self::assertStringContainsString('docs_locked', $guest->body());
        self::assertSame(404, $guest->get('/api/openapi.json')->getStatusCode(), 'no public static copy of the document');
        $guest->get('/api/docs');
        self::assertSame(422, $guest->post('/api/docs/unlock', ['password' => 'AAAAA-BBBBB-CCCCC-DDDDD'])->getStatusCode());
    }

    public function testPermissionGivesAccess(): void
    {
        [$dev] = AdminSession::login(['developer']);
        self::assertSame(200, $dev->get('/api/docs')->getStatusCode());
        self::assertStringContainsString('swagger-ui-bundle.js', $dev->body());
        self::assertStringNotContainsString('unsafe-inline', $dev->last()?->getHeaderLine('Content-Security-Policy') ?? '');
        self::assertStringNotContainsString('/api/docs/lock', $dev->body(), 'nothing to lock when access comes from the role');
        $r = $dev->get('/api/v1/openapi.json');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('3.1.0', json_decode($dev->body(), true)['openapi'] ?? null);

        $user = Fixtures::user(['user']);
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $user['username'], 'password' => $user['password']]);
        self::assertSame(401, $web->get('/api/docs')->getStatusCode(), 'a regular user without api.docs.view is locked out');
        self::assertSame(401, $web->get('/api/v1/openapi.json')->getStatusCode());
    }

    public function testSuperadminIssuesAndRevokesPassword(): void
    {
        [$super, $s] = AdminSession::login(['superadmin']);
        $super->get('/admin/api/docs');
        self::assertStringContainsString('Generate password', $super->body());
        AdminSession::reauth($super, $s);
        $super->get('/admin/api/docs');
        $super->post('/admin/api/docs', ['label' => 'e2e-partner', 'days' => '7']);
        self::assertSame(200, $super->last()?->getStatusCode());
        self::assertMatchesRegularExpression('~data-docs-password>([A-Z0-9]{5}(?:-[A-Z0-9]{5}){3})<~', $super->body());
        preg_match('~data-docs-password>([^<]+)<~', $super->body(), $m);
        $password = $m[1];
        $row = Fixtures::db()->createCommand("SELECT id, secret_hash FROM api_docs_passwords WHERE label = 'e2e-partner'")->queryOne();
        self::assertNotSame($password, $row['secret_hash'], 'only a hash is stored');

        $guest = new WebClient();
        $guest->get('/api/docs');
        $guest->post('/api/docs/unlock', ['password' => strtolower($password)]);
        self::assertSame('/api/docs', $guest->url());
        self::assertSame(200, $guest->last()?->getStatusCode());
        self::assertStringContainsString('swagger-ui-bundle.js', $guest->body());
        self::assertStringContainsString('/api/docs/lock', $guest->body());
        self::assertSame(200, $guest->get('/api/v1/openapi.json')->getStatusCode());

        // revoke: the unlocked browser loses access on its next request
        $super->get('/admin/api/docs');
        self::assertStringContainsString('e2e-partner', $super->body());
        $super->post('/admin/api/docs/' . $row['id'] . '/revoke');
        self::assertSame(401, $guest->get('/api/v1/openapi.json')->getStatusCode());
        self::assertSame(401, $guest->get('/api/docs')->getStatusCode());
        $guest->post('/api/docs/unlock', ['password' => $password]);
        self::assertSame(422, $guest->last()?->getStatusCode(), 'a revoked password does not work');
    }

    public function testAdminCannotIssuePasswords(): void
    {
        [$admin, $a] = AdminSession::login(['admin']);
        self::assertSame(200, $admin->get('/admin/api/docs')->getStatusCode());
        self::assertStringNotContainsString('Generate password', $admin->body());
        self::assertSame(200, $admin->get('/api/docs')->getStatusCode(), 'admin inherits api.docs.view from developer');
        AdminSession::reauth($admin, $a);
        $admin->get('/admin/api/docs');
        self::assertSame(403, $admin->post('/admin/api/docs', ['label' => 'e2e-x', 'days' => '1'])->getStatusCode());
    }
}
