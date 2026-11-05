<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Console\Command\UserMfaResetCommand;
use App\Tests\Support\Fixtures;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Break-glass `./yii user:mfa-reset`: requires a reason and confirmation (--force when non-interactive), removes
 * every factor and recovery code, ends the sessions and writes a critical audit record.
 */
final class UserMfaResetCommandTest extends IntegrationTestCase
{
    public function testResetsAllFactorsWithReasonAndAudit(): void
    {
        $u = Fixtures::user(['superadmin'], mfa: true);
        $tester = new CommandTester($this->get(UserMfaResetCommand::class));

        self::assertNotSame(0, $tester->execute(['login' => $u['username']], ['interactive' => false]), 'reason is required');
        self::assertNotSame(0, $tester->execute(['login' => $u['username'], '--reason' => 'lost phone'], ['interactive' => false]), 'non-interactive needs --force');
        self::assertTrue($this->mfaEnabled($u['id']), 'nothing changed yet');
        self::assertNotSame(0, $tester->execute(['login' => 'no-such-user-' . bin2hex(random_bytes(3)), '--reason' => 'x', '--force' => true]));

        self::assertSame(0, $tester->execute(['login' => $u['username'], '--reason' => 'lost phone and codes', '--force' => true]));
        self::assertStringContainsString('2FA reset for', $tester->getDisplay());
        self::assertFalse($this->mfaEnabled($u['id']));
        self::assertSame('[]', (string) $this->db->createCommand('SELECT mfa_recovery_codes FROM users WHERE id = :id', [':id' => $u['id']])->queryScalar());
        $audit = $this->db->createCommand(
            "SELECT severity, metadata FROM audit_log WHERE action = 'user.mfa_reset.console' AND entity_id = :id",
            [':id' => $u['id']],
        )->queryOne();
        self::assertSame('critical', $audit['severity'] ?? null);
        self::assertStringContainsString('lost phone and codes', (string) $audit['metadata']);
    }

    private function mfaEnabled(string $id): bool
    {
        return (bool) $this->db->createCommand('SELECT mfa_enabled FROM users WHERE id = :id', [':id' => $id])->queryScalar();
    }
}
