<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Api\ApiClientService;
use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Organizations\OrganizationService;
use App\Platform\Tenancy\TenantService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

/**
 * Application services of sections 02/05/06: OrganizationService, TenantService, ApiClientService.
 */
final class IdentityAdminServicesTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $tenants = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->tenants as $t) {
            $this->db->createCommand()->delete('api_clients', ['tenant_id' => $t])->execute();
            $this->db->createCommand()->delete('users', ['tenant_id' => $t])->execute();
            for ($i = 0; $i < 10; $i++) { // delete leaves first (parent FK is RESTRICT)
                $this->db->createCommand('DELETE FROM organizations o WHERE o.tenant_id = :t AND NOT EXISTS (SELECT 1 FROM organizations c WHERE c.parent_id = o.id)', [':t' => $t])->execute();
            }
            $this->db->createCommand()->delete('tenants', ['id' => $t])->execute();
        }
        $this->db->createCommand("DELETE FROM api_clients WHERE name LIKE 'it-%'")->execute();
    }

    public function testOrganizationTreeCyclePreventionAndTenantIsolation(): void
    {
        $orgs = $this->get(OrganizationService::class);
        $tenant = $this->tenant();
        $admin = $this->actor(['platform_admin'], $tenant);
        $a = $orgs->create($admin, ['tenant_id' => $tenant, 'code' => 'a', 'name' => 'A']);
        $b = $orgs->create($admin, ['tenant_id' => $tenant, 'code' => 'b', 'name' => 'B', 'parent_id' => $a['id']]);
        $c = $orgs->create($admin, ['tenant_id' => $tenant, 'code' => 'c', 'name' => 'C', 'parent_id' => $b['id']]);

        $tree = $orgs->tree($admin, $tenant);
        self::assertSame(['A', 'B', 'C'], array_column($tree, 'name'));
        self::assertSame([0, 1, 2], array_column($tree, 'depth'));

        foreach ([$a['id'], $c['id']] as $badParent) {
            try {
                $orgs->update($admin, $a['id'], ['parent_id' => $badParent]); // itself / its grandchild
                self::fail('cycle must be refused');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('parent_id', $e->errors());
            }
        }
        try {
            $orgs->create($admin, ['tenant_id' => $tenant, 'code' => 'a', 'name' => 'Duplicate']);
            self::fail('duplicate code');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('code', $e->errors());
        }
        try {
            $orgs->delete($admin, $a['id']);
            self::fail('parent with children cannot be deleted');
        } catch (ConflictException $e) {
            self::assertSame('has_children', $e->errorCode());
        }

        // Parent from another tenant is refused; tenant-scoped actor sees only its tenant
        $other = $this->tenant();
        $foreign = $orgs->create($admin, ['tenant_id' => $other, 'code' => 'x', 'name' => 'X']);
        try {
            $orgs->update($admin, $c['id'], ['parent_id' => $foreign['id']]);
            self::fail('cross-tenant parent');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('parent_id', $e->errors());
        }
        $scoped = $this->actor(['business_admin'], $tenant);
        $this->expectException(NotFoundException::class);
        $orgs->get($scoped, $foreign['id']);
    }

    public function testTenantSuspendRevokesSessionsAndGuards(): void
    {
        $service = $this->get(TenantService::class);
        $registry = $this->get(SessionRegistry::class);
        $admin = $this->actor(['platform_admin']);
        $tenant = $service->create($admin, ['code' => 'it-' . bin2hex(random_bytes(3)), 'name' => 'IT tenant', 'domain' => 'it-' . bin2hex(random_bytes(3)) . '.example.uz']);
        $this->tenants[] = (string) $tenant['id'];
        self::assertSame([$tenant['id']], array_column($service->list($admin, ['q' => strtoupper((string) $tenant['code'])], 1, 25, null)->items, 'id'), 'case-insensitive search');
        self::assertSame(0, $service->list($admin, ['q' => '%_none_%' . bin2hex(random_bytes(4))], 1, 25, null)->total, 'LIKE wildcards are escaped');
        $member = Fixtures::user(['user'], extra: ['tenant_id' => $tenant['id']]);
        $registry->start('sess-' . bin2hex(random_bytes(8)), $member['id'], 'password', '127.0.0.1', 'phpunit', false);

        $result = $service->setStatus($admin, (string) $tenant['id'], 'suspended');
        self::assertSame(1, $result['sessions_revoked']);
        self::assertSame([], $registry->activeForUser($member['id']));
        self::assertSame('suspended', $service->get($admin, (string) $tenant['id'])['status']);

        try {
            $service->create($admin, ['code' => $tenant['code'], 'name' => 'dup']);
            self::fail('duplicate code');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('code', $e->errors());
        }
        try {
            $default = (string) $this->db->createCommand("SELECT id FROM tenants WHERE code = 'default'")->queryScalar();
            $service->setStatus($admin, $default, 'suspended');
            self::fail('default tenant is protected');
        } catch (ConflictException $e) {
            self::assertSame('default_tenant', $e->errorCode());
        }
        try {
            $service->delete($admin, (string) $tenant['id']);
            self::fail('tenant with users cannot be deleted');
        } catch (ConflictException $e) {
            self::assertSame('has_users', $e->errorCode());
        }

        $auditor = $this->actor(['auditor']);
        $this->expectException(AccessDeniedException::class);
        $service->setStatus($auditor, (string) $tenant['id'], 'active');
    }

    public function testTenantScopedActorCannotSeeForeignTenant(): void
    {
        $service = $this->get(TenantService::class);
        $foreign = $this->tenant();
        $scoped = $this->actor(['auditor']);
        self::assertNotContains($foreign, array_column($service->list($scoped, [], 1, 200, null)->items, 'id'));
        $this->expectException(NotFoundException::class);
        $service->get($scoped, $foreign);
    }

    public function testApiClientSecretHashedOnceAndAuthenticate(): void
    {
        $api = $this->get(ApiClientService::class);
        $admin = $this->actor(['platform_admin']);
        $result = $api->create($admin, ['name' => 'it-client', 'scopes' => 'projects.read projects.*', 'allowed_ips' => "10.0.0.0/8\n192.168.1.5", 'rate_limit_per_minute' => '120']);
        $client = $result['client'];
        $secret = $result['secret'];
        self::assertMatchesRegularExpression('/^yep_s_[A-Za-z0-9_-]{43}$/', $secret);
        self::assertMatchesRegularExpression(ApiClientService::CLIENT_ID_PATTERN, $client['client_id']);
        self::assertSame(['10.0.0.0/8', '192.168.1.5/32'], $client['allowed_ips']);

        $row = (new Query($this->db))->from('api_clients')->where(['id' => $client['id']])->one();
        self::assertStringStartsWith('$argon2id$', (string) $row['secret_hash']);
        self::assertStringNotContainsString($secret, json_encode($row));
        $audit = (new Query($this->db))->select(['before', 'after', 'metadata'])->from('audit_log')->where(['entity_id' => $client['id']])->all();
        self::assertStringNotContainsString($secret, json_encode($audit), 'secret never written to audit');

        self::assertNotNull($api->authenticate($client['client_id'], $secret, '10.1.2.3'));
        self::assertNull($api->authenticate($client['client_id'], $secret, '8.8.8.8'), 'IP allowlist');
        self::assertNull($api->authenticate($client['client_id'], $secret, null));
        self::assertNull($api->authenticate($client['client_id'], $secret . 'x', '10.1.2.3'));
        self::assertNull($api->authenticate('yepc_' . str_repeat('0', 24), $secret, '10.1.2.3'));
        self::assertNotNull((new Query($this->db))->select('last_used_at')->from('api_clients')->where(['id' => $client['id']])->scalar());

        $new = $api->rotateSecret($admin, (string) $client['id']);
        self::assertNull($api->authenticate($client['client_id'], $secret, '10.1.2.3'), 'old secret revoked');
        self::assertNotNull($api->authenticate($client['client_id'], $new, '10.1.2.3'));

        $api->setEnabled($admin, (string) $client['id'], false);
        self::assertNull($api->authenticate($client['client_id'], $new, '10.1.2.3'), 'disabled');

        $this->db->createCommand()->update('api_clients', ['enabled' => true, 'expires_at' => '2020-01-01T00:00:00Z'], ['id' => $client['id']])->execute();
        self::assertNull($api->authenticate($client['client_id'], $new, '10.1.2.3'), 'expired');

        try {
            $api->create($admin, ['name' => 'it-bad', 'scopes' => 'Bad Scope!', 'allowed_ips' => '10.0.0.0/33 nonsense', 'rate_limit_per_minute' => '0']);
            self::fail('validation');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('allowed_ips', $e->errors());
            self::assertArrayHasKey('scopes', $e->errors());
            self::assertArrayHasKey('rate_limit_per_minute', $e->errors());
        }
        $api->delete($admin, (string) $client['id']);
        self::assertNull($api->authenticate($client['client_id'], $new, '10.1.2.3'));
    }

    public function testApiClientTenantIsolationAndSuspendedTenant(): void
    {
        $api = $this->get(ApiClientService::class);
        $tenant = $this->tenant();
        $cross = $this->actor(['platform_admin']);
        $result = $api->create($cross, ['name' => 'it-tenant-client', 'scopes' => 'projects.read', 'tenant_id' => $tenant]);
        self::assertNotNull($api->authenticate($result['client']['client_id'], $result['secret'], '127.0.0.1'));
        $this->db->createCommand()->update('tenants', ['status' => 'suspended'], ['id' => $tenant])->execute();
        self::assertNull($api->authenticate($result['client']['client_id'], $result['secret'], '127.0.0.1'), 'suspended tenant');

        $scoped = $this->actor(['auditor']); // default tenant, no tenants.cross
        $this->expectException(NotFoundException::class);
        $api->get($scoped, (string) $result['client']['id']);
    }

    private function tenant(): string
    {
        $id = (string) $this->db->createCommand("INSERT INTO tenants (code, name) VALUES (:c, 'IT tenant') RETURNING id", [':c' => 't-' . bin2hex(random_bytes(4))])->queryScalar();
        $this->tenants[] = $id;
        return $id;
    }

    private function actor(array $roles, ?string $tenantId = null): \App\Platform\Identity\Domain\User
    {
        $u = Fixtures::user($roles, extra: $tenantId !== null ? ['tenant_id' => $tenantId] : []);
        return $this->get(UserRepository::class)->find($u['id']);
    }
}
