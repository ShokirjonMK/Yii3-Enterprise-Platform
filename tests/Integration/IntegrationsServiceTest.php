<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Integrations\IntegrationService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

/** IntegrationService: validation, encrypted credentials, optimistic locking, permissions, audit, connection test. */
final class IntegrationsServiceTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        $this->db->createCommand("DELETE FROM integrations WHERE code LIKE 'it-%'")->execute();
        parent::tearDown();
    }

    public function testCrudSecretAndStatus(): void
    {
        $svc = $this->get(IntegrationService::class);
        $admin = Fixtures::user(['platform_admin'])['id'];
        $code = 'it-' . bin2hex(random_bytes(3));
        try {
            $svc->create($admin, ['code' => 'Bad Code', 'name' => '', 'base_url' => 'gopher://x', 'headers' => '{"Authorization":"x"}', 'timeout_ms' => '5']);
            self::fail('validation expected');
        } catch (ValidationException $e) {
            self::assertEqualsCanonicalizing(['code', 'name', 'base_url', 'headers', 'timeout_ms'], array_keys($e->errors()));
        }
        $id = $svc->create($admin, ['code' => $code, 'name' => 'IT', 'base_url' => 'https://api.example.test', 'auth_type' => 'api_key', 'api_key_header' => 'X-Key', 'headers' => '{"X-Tenant":"acme"}']);
        $row = $svc->find($id);
        self::assertSame('unconfigured', $row['status']);
        self::assertFalse((bool) $row['enabled']);

        try {
            $svc->setEnabled($admin, $id, true);
            self::fail('enabling without secret must fail');
        } catch (ValidationException) {
        }
        $svc->updateSecurity($admin, $id, 'api-key-VALUE-42', false, false);
        $raw = (string) (new Query($this->db))->select('secret_enc')->from('integrations')->where(['id' => $id])->scalar();
        self::assertStringStartsWith('v1:', $raw);
        self::assertStringNotContainsString('api-key-VALUE-42', $raw);
        $cfg = $svc->config($code);
        self::assertSame('api-key-VALUE-42', $cfg->secret);
        self::assertSame('X-Key', $cfg->authConfig['api_key_header']);
        self::assertSame(['X-Tenant' => 'acme'], $cfg->headers);
        $svc->setEnabled($admin, $id, true);

        $svc->update($admin, $id, ['name' => 'IT renamed', 'base_url' => 'https://api.example.test', 'auth_type' => 'api_key', 'max_retries' => '1'], (int) $row['version'] + 2);
        try {
            $svc->update($admin, $id, ['name' => 'stale'], (int) $row['version']);
            self::fail('stale version must conflict');
        } catch (ConflictException) {
        }

        $audit = (new Query($this->db))->select('action')->from('audit_log')->where(['entity_type' => 'integration', 'entity_id' => $id])->column();
        foreach (['integration.created', 'integration.security_changed', 'integration.enabled', 'integration.updated'] as $a) {
            self::assertContains($a, $audit);
        }
        self::assertStringNotContainsString('api-key-VALUE-42', json_encode((new Query($this->db))->from('audit_log')->where(['entity_id' => $id])->all()));

        // test connection against an unresolvable host → failed + last_error, still audited
        $svc->update($admin, $id, ['name' => 'IT', 'base_url' => 'https://does-not-exist.invalid', 'auth_type' => 'api_key', 'health_path' => '/health']);
        $result = $svc->testConnection($admin, $id);
        self::assertFalse($result['ok']);
        self::assertSame('failed', $svc->find($id)['status']);
        self::assertNotNull($svc->find($id)['last_error']);

        $svc->delete($admin, $id);
        self::assertFalse((new Query($this->db))->from('integrations')->where(['id' => $id])->exists());
    }

    public function testManagePermissionRequired(): void
    {
        $auditor = Fixtures::user(['auditor'])['id'];
        $this->expectException(AccessDeniedException::class);
        $this->get(IntegrationService::class)->create($auditor, ['code' => 'it-denied', 'name' => 'x']);
    }
}
