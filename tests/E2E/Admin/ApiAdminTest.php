<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Platform\Api\ApiClientService;
use App\Platform\Identity\Application\ApiTokenService;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Query\Query;

/**
 * Section 06 · API Management.
 */
final class ApiAdminTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::db()->createCommand("DELETE FROM api_clients WHERE name LIKE 'e2e-%'")->execute();
        Fixtures::cleanup();
    }

    public function testClientSecretShownOnceHashStoredRotateAndDelete(): void
    {
        [$web, $admin] = AdminSession::login(['platform_admin']);
        $db = Fixtures::db();
        $name = 'e2e-' . bin2hex(random_bytes(3));

        $web->get('/admin/api/clients/create');
        $r = $web->post('/admin/api/clients/create', ['name' => $name, 'scopes' => 'projects.read', 'allowed_ips' => '999.1.1.1/8', 'rate_limit_per_minute' => '60']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Invalid IP address or CIDR', $web->body());

        $r = $web->post('/admin/api/clients/create', ['name' => $name, 'scopes' => 'projects.read projects.write', 'allowed_ips' => "127.0.0.1\n10.0.0.0/8", 'rate_limit_per_minute' => '60']);
        self::assertSame(201, $r->getStatusCode(), $web->body());
        self::assertStringContainsString('no-store', $r->getHeaderLine('Cache-Control'));
        preg_match('/data-client-secret>([^<]+)</', $web->body(), $m);
        $secret = html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5);
        self::assertMatchesRegularExpression('/^yep_s_[A-Za-z0-9_-]{43}$/', $secret);

        $row = (new Query($db))->from('api_clients')->where(['name' => $name])->one();
        self::assertStringStartsWith('$argon2id$', (string) $row['secret_hash']);
        self::assertTrue(password_verify($secret, (string) $row['secret_hash']));
        self::assertStringNotContainsString($secret, json_encode($row), 'only the hash is stored');
        $id = (string) $row['id'];

        $web->get("/admin/api/clients/$id");
        self::assertStringNotContainsString($secret, $web->body(), 'secret is not shown again');
        self::assertStringContainsString((string) $row['client_id'], $web->body());

        /** @var ApiClientService $service */
        $service = TestContainer::service(ApiClientService::class);
        self::assertNotNull($service->authenticate((string) $row['client_id'], $secret, '127.0.0.1'));
        self::assertNull($service->authenticate((string) $row['client_id'], $secret, '203.0.113.9'));

        // disable/enable
        $web->post("/admin/api/clients/$id/disable");
        self::assertNull($service->authenticate((string) $row['client_id'], $secret, '127.0.0.1'));
        $web->post("/admin/api/clients/$id/enable");

        // rotate requires re-auth
        self::expireReauth($admin['id']);
        $web->get("/admin/api/clients/$id");
        $web->post("/admin/api/clients/$id/rotate");
        self::assertSame('/auth/confirm', $web->url());
        AdminSession::reauth($web, $admin);
        $web->get("/admin/api/clients/$id");
        $web->post("/admin/api/clients/$id/rotate");
        preg_match('/data-client-secret>([^<]+)</', $web->body(), $m2);
        $newSecret = html_entity_decode($m2[1] ?? '', ENT_QUOTES | ENT_HTML5);
        self::assertNotSame($secret, $newSecret);
        self::assertNull($service->authenticate((string) $row['client_id'], $secret, '127.0.0.1'));
        self::assertNotNull($service->authenticate((string) $row['client_id'], $newSecret, '127.0.0.1'));

        $audit = (new Query($db))->select(['action', 'before', 'after', 'metadata'])->from('audit_log')->where(['entity_type' => 'api_client', 'entity_id' => $id])->all();
        self::assertContains('api.client.created', array_column($audit, 'action'));
        self::assertContains('api.client.secret_rotated', array_column($audit, 'action'));
        self::assertStringNotContainsString($newSecret, json_encode($audit));

        $web->get("/admin/api/clients/$id");
        $web->post("/admin/api/clients/$id/delete");
        self::assertFalse((new Query($db))->from('api_clients')->where(['id' => $id])->exists());
    }

    public function testPersonalTokensListAndRevokeAndUsage(): void
    {
        [$web] = AdminSession::login(['platform_admin']);
        $owner = Fixtures::user(['user']);
        /** @var ApiTokenService $tokens */
        $tokens = TestContainer::service(ApiTokenService::class);
        $issued = $tokens->issuePersonal($owner['id'], 'e2e-token', ['projects.read'], 30);

        $web->get('/admin/api/tokens?q=' . $owner['username']);
        self::assertStringContainsString('e2e-token', $web->body());
        self::assertStringNotContainsString($issued['token'], $web->body());
        self::assertStringContainsString('Personal tokens', $web->body());

        $web->post('/admin/api/tokens/' . $issued['id'] . '/revoke');
        self::assertNotNull(Fixtures::db()->createCommand('SELECT revoked_at FROM api_tokens WHERE id = :id', [':id' => $issued['id']])->queryScalar());
        self::assertNull($tokens->authenticate($issued['token']));
    }

    public function testLowerRolesDeniedAndCsrfRequired(): void
    {
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/api')->getStatusCode());
        self::assertSame(403, $web->get('/admin/api/clients/create')->getStatusCode());
        $web->get('/admin/api');
        self::assertSame(403, $web->post('/admin/api/clients/create', ['name' => 'e2e-x', 'scopes' => 'a.b'])->getStatusCode());

        [$sa] = AdminSession::login(['superadmin']);
        $sa->get('/admin/api/clients/create');
        $r = $sa->post('/admin/api/clients/create', ['name' => 'e2e-nocsrf', 'scopes' => 'a.b'], [], false);
        self::assertContains($r->getStatusCode(), [400, 403, 422]);
        self::assertFalse((new Query(Fixtures::db()))->from('api_clients')->where(['name' => 'e2e-nocsrf'])->exists());
    }

    private static function expireReauth(string $userId): void
    {
        Fixtures::db()->createCommand("UPDATE user_sessions SET reauth_at = now() - interval '1 hour' WHERE user_id = :u", [':u' => $userId])->execute();
    }
}
