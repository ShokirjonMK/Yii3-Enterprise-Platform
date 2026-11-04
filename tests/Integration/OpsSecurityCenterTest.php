<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Identity\Application\ApiTokenService;
use App\Platform\Identity\Application\SessionRegistry;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Monitoring\SecurityCenter;
use App\Platform\Monitoring\SecurityPosture;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Tests\Support\Fixtures;
use Yiisoft\Db\Query\Query;

final class OpsSecurityCenterTest extends IntegrationTestCase
{
    private function user(array $roles, bool $mfa = false): User
    {
        return $this->get(UserRepository::class)->find(Fixtures::user($roles, $mfa)['id']);
    }

    public function testPostureIsComputedFromRealState(): void
    {
        $noMfa = $this->user(['platform_admin'], false);
        /** @var SecurityPosture $posture */
        $posture = $this->get(SecurityPosture::class);
        $checks = array_column($posture->checks(), null, 'id');
        foreach (['env', 'debug', 'https', 'expose_php', 'mfa_privileged', 'stale_passwords', 'locked', 'metrics_token', 'app_secret', 'csp', 'gii', 'local_login'] as $id) {
            self::assertArrayHasKey($id, $checks);
            self::assertContains($checks[$id]['status'], ['pass', 'warn', 'fail', 'info']);
        }
        self::assertSame('fail', $checks['mfa_privileged']['status'], 'a privileged user without MFA fails the check');
        self::assertContains($noMfa->id, array_column($posture->privilegedWithoutMfa(), 'id'));
        self::assertSame('pass', $checks['csp']['status']);
        $s = SecurityPosture::summarize($posture->checks());
        self::assertGreaterThan(0, $s['pass']);
    }

    public function testSessionRevokeUnlockAndTokenRevocation(): void
    {
        $admin = $this->user(['security_admin']);
        $target = $this->user(['user']);
        /** @var SessionRegistry $sessions */
        $sessions = $this->get(SessionRegistry::class);
        $sessions->start('sid-a-' . bin2hex(random_bytes(8)), $target->id, 'password', '10.0.0.1', 'phpunit', false);
        $sidB = 'sid-b-' . bin2hex(random_bytes(8));
        $sessions->start($sidB, $target->id, 'password', '10.0.0.2', 'phpunit', false);
        /** @var SecurityCenter $center */
        $center = $this->get(SecurityCenter::class);

        $page = $center->activeSessions($admin, ['q' => $target->username], 1, null);
        self::assertSame(2, $page->total);

        $center->revokeSession($admin, SessionRegistry::hash($sidB), null);
        self::assertCount(1, $sessions->activeForUser($target->id));
        self::assertSame(1, $center->revokeAllSessions($admin, $target->id, null));
        self::assertCount(0, $sessions->activeForUser($target->id));

        $this->get(ApiTokenService::class)->issuePersonal($target->id, 'ci', ['*'], 30);
        self::assertSame(1, $center->revokeApiTokens($admin, $target->id));

        $this->db->createCommand()->update('users', ['failed_logins' => 7, 'locked_until' => (new \DateTimeImmutable('+10 minutes'))->format(DATE_ATOM)], ['id' => $target->id])->execute();
        self::assertContains($target->id, array_column($center->lockedUsers($admin), 'id'));
        $center->unlock($admin, $target->id);
        $row = (new Query($this->db))->from('users')->where(['id' => $target->id])->one();
        self::assertNull($row['locked_until']);
        self::assertSame(0, (int) $row['failed_logins']);
        foreach (['security.session_revoked', 'security.sessions_revoked_all', 'security.api_tokens_revoked', 'security.user_unlocked'] as $action) {
            self::assertTrue((new Query($this->db))->from('audit_log')->where(['action' => $action, 'entity_id' => $target->id, 'category' => 'security'])->exists(), $action);
        }
    }

    public function testAuditorCannotManage(): void
    {
        $auditor = $this->user(['auditor']);
        $target = $this->user(['user']);
        $center = $this->get(SecurityCenter::class);
        foreach ([fn() => $center->unlock($auditor, $target->id), fn() => $center->revokeApiTokens($auditor, $target->id), fn() => $center->revokeAllSessions($auditor, $target->id, null)] as $fn) {
            try {
                $fn();
                self::fail('auditor performed a manage action');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
