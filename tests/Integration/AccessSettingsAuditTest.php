<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Platform\Audit\AuditVerifier;
use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Identity\Application\MfaService;
use App\Platform\Identity\Application\UserService;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

final class AccessSettingsAuditTest extends IntegrationTestCase
{
    public function testDefaultDenyAndRoleInheritance(): void
    {
        $access = $this->get(AccessService::class);
        $user = Fixtures::user(['user']);
        $auditor = Fixtures::user(['auditor']);
        self::assertFalse($access->can($user['id'], 'admin.access'));
        self::assertFalse($access->can($user['id'], 'does.not.exist'));
        self::assertTrue($access->can($auditor['id'], 'audit.view'));
        self::assertTrue($access->can($auditor['id'], 'profile.self'), 'inherited from role user');
        self::assertFalse($access->can($auditor['id'], 'users.manage'), 'auditor is read-only');
        self::assertFalse($access->can(null, 'profile.self'));
    }

    public function testOnlySuperadminGrantsAdminRolesAndLastSuperadminProtected(): void
    {
        $access = $this->get(AccessService::class);
        $pa = Fixtures::user(['platform_admin']);
        $target = Fixtures::user(['user']);
        try {
            $access->setRoles($pa['id'], $target['id'], ['user', 'superadmin']);
            self::fail('platform_admin must not grant superadmin');
        } catch (AccessDeniedException) {
        }
        $access->setRoles($pa['id'], $target['id'], ['user', 'auditor']);
        self::assertContains('auditor', $access->directRoles($target['id']));

        try {
            $access->setRoles($pa['id'], $pa['id'], ['user']);
            self::fail('self role change must be refused');
        } catch (AccessDeniedException) {
        }

        // Last-superadmin protection (test DB may already contain superadmins: isolate by archiving them temporarily)
        $others = (new Query($this->db))->select('a.user_id')->from(['a' => 'yii_rbac_assignment'])->where(['item_name' => 'superadmin'])->column();
        $this->db->createCommand()->update('users', ['status' => 'blocked'], ['id' => $others])->execute();
        try {
            $s1 = Fixtures::user(['superadmin']);
            $s2 = Fixtures::user(['superadmin']);
            $s3 = Fixtures::user(['superadmin']);
            $access->setRoles($s1['id'], $s3['id'], ['user']);       // ok: s1 and s2 remain superadmins
            self::assertNotContains('superadmin', $access->directRoles($s3['id']));

            // s1's account gets blocked; s2 is now the only ACTIVE superadmin → cannot be archived
            $this->db->createCommand()->update('users', ['status' => 'blocked'], ['id' => $s1['id']])->execute();
            /** @var UserService $users */
            $users = $this->get(UserService::class);
            $repo = $this->get(UserRepository::class);
            try {
                $users->archive($repo->find($s1['id']), $s2['id']);
                self::fail('last active superadmin must be protected');
            } catch (ConflictException $e) {
                self::assertSame('last_superadmin', $e->errorCode());
            }
            $this->expectException(ConflictException::class);
            $users->archive($repo->find($s2['id']), $s2['id']); // self-deactivation
        } finally {
            if ($others !== []) {
                $this->db->createCommand()->update('users', ['status' => 'active'], ['id' => $others])->execute();
            }
        }
    }

    public function testTenantIsolationForUserAdministration(): void
    {
        $tenant = (string) $this->db->createCommand("INSERT INTO tenants (code, name) VALUES (:c, 'Other') RETURNING id", [':c' => 't' . bin2hex(random_bytes(4))])->queryScalar();
        $foreign = Fixtures::user(['user'], extra: ['tenant_id' => $tenant]);
        $ba = Fixtures::user(['business_admin']); // default tenant, no tenants.cross
        $users = $this->get(UserService::class);
        $actor = $this->get(UserRepository::class)->find($ba['id']);
        $this->expectException(NotFoundException::class);
        $users->get($actor, $foreign['id']);
    }

    public function testSettingsVersioningRollbackAndCriticalGuard(): void
    {
        $settings = $this->get(SettingsService::class);
        $admin = Fixtures::user(['platform_admin']);
        $super = Fixtures::user(['superadmin']);

        $settings->set($admin['id'], 'api.default_page_size', 40, comment: 'first');
        $settings->set($admin['id'], 'api.default_page_size', 60, comment: 'second');
        self::assertSame(60, $settings->get('api.default_page_size'));
        $history = $settings->history('api.default_page_size');
        self::assertGreaterThanOrEqual(2, count($history));
        $settings->rollback($admin['id'], 'api.default_page_size', (int) $history[1]['version']);
        self::assertSame(40, $settings->get('api.default_page_size'));

        try {
            $settings->set($admin['id'], 'api.default_page_size', 100000);
            self::fail('range validation');
        } catch (ValidationException) {
        }
        try {
            $settings->set($admin['id'], 'security.login_max_attempts', 10);
            self::fail('critical setting requires superadmin');
        } catch (AccessDeniedException) {
        }
        $settings->set($super['id'], 'security.login_max_attempts', 6);
        self::assertSame(6, $settings->get('security.login_max_attempts'));
        $settings->reset($super['id'], 'security.login_max_attempts');
        $settings->reset($admin['id'], 'api.default_page_size');
        self::assertSame(25, $settings->get('api.default_page_size'));
    }

    public function testSecretSettingIsEncryptedAtRest(): void
    {
        $settings = $this->get(SettingsService::class);
        $super = Fixtures::user(['superadmin']);
        $settings->set($super['id'], 'notifications.sms_gateway_token', 'very-secret-token');
        $raw = (string) (new Query($this->db))->select('value')->from('settings')->where(['key' => 'notifications.sms_gateway_token', 'scope' => 'global'])->scalar();
        self::assertStringNotContainsString('very-secret-token', $raw);
        self::assertSame('very-secret-token', $settings->get('notifications.sms_gateway_token'));
        self::assertSame('***', $settings->history('notifications.sms_gateway_token')[0]['value']);
        $settings->reset($super['id'], 'notifications.sms_gateway_token');
    }

    public function testFeatureFlagsRolloutCriticalAndDependencies(): void
    {
        $flags = $this->get(FeatureFlagService::class);
        $admin = Fixtures::user(['platform_admin']);
        self::assertFalse($flags->isEnabled('unknown.flag'), 'unknown flag = off');

        try {
            $flags->disable($admin['id'], 'security.audit');
            self::fail('critical flag must not be disabled');
        } catch (ConflictException) {
        }

        $flags->configure($admin['id'], 'module.projects', ['rollout_percentage' => 50]);
        $on = 0;
        for ($i = 0; $i < 200; $i++) {
            $on += $flags->isEnabled('module.projects', null, 'user-' . $i) ? 1 : 0;
        }
        self::assertGreaterThan(60, $on);
        self::assertLessThan(140, $on);
        self::assertSame($flags->isEnabled('module.projects', null, 'user-7'), $flags->isEnabled('module.projects', null, 'user-7'), 'sticky');
        $flags->configure($admin['id'], 'module.projects', ['rollout_percentage' => 100]);

        // telegram channel is not configured → dependency validation blocks enabling
        try {
            $flags->enable($admin['id'], 'notifications.telegram');
            self::fail('dependency validation');
        } catch (ValidationException $e) {
            self::assertStringContainsString('telegram', $e->getMessage());
        }
    }

    public function testAuditRecordsAreImmutableAndTamperEvident(): void
    {
        $audit = $this->get(AuditLogger::class);
        $audit->record('test.audit', 'thing', 'x1', ['a' => 1], ['a' => 2], ['password' => 'p']);
        $row = (new Query($this->db))->from('audit_log')->where(['action' => 'test.audit'])->orderBy(['id' => SORT_DESC])->one();
        self::assertStringNotContainsString('"p"', (string) $row['metadata'], 'secrets redacted');

        try {
            $this->db->createCommand()->update('audit_log', ['action' => 'hacked'], ['id' => $row['id']])->execute();
            self::fail('UPDATE must be rejected');
        } catch (\Throwable $e) {
            self::assertStringContainsString('append-only', $e->getMessage());
        }

        $verifier = $this->get(AuditVerifier::class);
        $r = $verifier->verify((string) $row['occurred_at']);
        self::assertSame([], $r['invalid']);

        // A DBA bypassing the trigger is still detected by the HMAC
        $this->db->createCommand('ALTER TABLE audit_log DISABLE TRIGGER audit_log_no_update')->execute();
        $this->db->createCommand()->update('audit_log', ['entity_id' => 'tampered'], ['id' => $row['id']])->execute();
        $this->db->createCommand('ALTER TABLE audit_log ENABLE TRIGGER audit_log_no_update')->execute();
        self::assertContains((int) $row['id'], $verifier->verify((string) $row['occurred_at'])['invalid']);
    }

    public function testMfaReplayProtection(): void
    {
        $u = Fixtures::user(['user'], mfa: true);
        $mfa = $this->get(MfaService::class);
        $repo = $this->get(UserRepository::class);
        $code = Fixtures::totp((string) $u['mfa_secret']);
        self::assertTrue($mfa->verifyForUser($repo->find($u['id']), $code));
        self::assertFalse($mfa->verifyForUser($repo->find($u['id']), $code), 'same code cannot be reused');
        self::assertFalse($mfa->verifyForUser($repo->find($u['id']), '123'));
    }
}
