<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\Fixtures;

/** /api/v1/users over UserService (users.view / users.manage, tenant isolation, versioning). */
final class ApiUsersTest extends ApiTestCase
{
    public function testUserAdministrationFlow(): void
    {
        $admin = Fixtures::user(['business_admin']);
        $token = $this->token($admin, 'users.read users.write')['access_token'];
        $username = 't-' . bin2hex(random_bytes(5));

        $r = $this->api('POST', '/api/v1/users', $token, ['username' => $username, 'email' => $username . '@test.local', 'full_name' => 'Api Created']);
        self::assertSame(201, $r->getStatusCode(), $this->http->body());
        $d = $this->json($r);
        $id = $d['data']['id'];
        self::assertSame($username, $d['data']['username']);
        self::assertNotEmpty($d['meta']['temporary_password'], 'generated temporary password is returned once');
        self::assertArrayNotHasKey('password_hash', $d['data']);
        // register for cleanup
        Fixtures::db()->createCommand()->update('users', ['must_change_password' => true], ['id' => $id])->execute();

        $r = $this->api('GET', '/api/v1/users/' . $id, $token);
        self::assertSame(200, $r->getStatusCode());
        $etag = $r->getHeaderLine('ETag');
        self::assertMatchesRegularExpression('/^"\d+"$/', $etag);

        $r = $this->api('PATCH', '/api/v1/users/' . $id, $token, ['full_name' => 'Renamed'], ['If-Match' => $etag]);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        self::assertSame('Renamed', $this->json($r)['data']['full_name']);
        $this->assertError($this->api('PATCH', '/api/v1/users/' . $id, $token, ['full_name' => 'Stale'], ['If-Match' => $etag]), 409, 'stale_version');
        $this->assertError($this->api('PATCH', '/api/v1/users/' . $id, $token, ['email' => 'not-an-email', 'version' => 99]), 422, 'validation_failed');

        $r = $this->api('POST', "/api/v1/users/$id/block", $token);
        self::assertSame('blocked', $this->json($r)['data']['status']);
        $r = $this->api('POST', "/api/v1/users/$id/unblock", $token);
        self::assertSame('active', $this->json($r)['data']['status']);

        $r = $this->api('GET', '/api/v1/users?q=' . $username . '&filter[status]=active&sort=-created_at', $token);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        $list = $this->json($r);
        self::assertSame([$id], array_column($list['data'], 'id'));
        self::assertSame(1, $list['meta']['pagination']['total']);
        self::assertContains('user', $list['data'][0]['roles']);

        // cleanup the API-created user
        Fixtures::db()->createCommand()->update('users', ['status' => 'archived', 'deleted_at' => new \Yiisoft\Db\Expression\Expression('now()')], ['id' => $id])->execute();
    }

    public function testCannotBlockYourself(): void
    {
        $admin = Fixtures::user(['business_admin']);
        $token = $this->token($admin)['access_token'];
        $this->assertError($this->api('POST', "/api/v1/users/{$admin['id']}/block", $token), 409, 'self_deactivation');
    }

    public function testForeignTenantUserIs404AndPermissionsEnforced(): void
    {
        $db = Fixtures::db();
        $tenant = (string) $db->createCommand("INSERT INTO tenants (code, name) VALUES (:c, 'Users foreign tenant') RETURNING id", [':c' => 't-' . bin2hex(random_bytes(4))])->queryScalar();
        $foreign = Fixtures::user(['user'], extra: ['tenant_id' => $tenant]);
        $token = $this->token(Fixtures::user(['business_admin']))['access_token'];
        $this->assertError($this->api('GET', '/api/v1/users/' . $foreign['id'], $token), 404, 'not_found');
        $this->assertError($this->api('POST', "/api/v1/users/{$foreign['id']}/block", $token), 404, 'not_found');

        $plain = $this->token(Fixtures::user(['user']))['access_token'];
        $this->assertError($this->api('GET', '/api/v1/users', $plain), 403, 'forbidden');
    }
}
