<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Identity\Application\LoginHistory;
use App\Platform\Identity\Application\LoginThrottle;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Tests\Support\Fixtures;

/**
 * Sign-in history: a user sees only the own attempts (incl. failed ones on the account), holders of
 * users.login_history see everybody's, administrators without tenants.cross only their tenant.
 */
final class LoginHistoryTest extends IntegrationTestCase
{
    private ?string $otherTenant = null;

    protected function tearDown(): void
    {
        if ($this->otherTenant !== null) {
            $this->db->createCommand('DELETE FROM login_attempts WHERE tenant_id = :t', [':t' => $this->otherTenant])->execute();
            $this->db->createCommand()->update('users', ['tenant_id' => null], ['tenant_id' => $this->otherTenant])->execute();
            $this->db->createCommand()->delete('tenants', ['id' => $this->otherTenant])->execute();
        }
        parent::tearDown();
    }

    public function testOwnHistoryAllUsersAndTenantScope(): void
    {
        $this->otherTenant = (string) $this->db->createCommand(
            "INSERT INTO tenants (code, name) VALUES (:c, 'Other') RETURNING id",
            [':c' => 'lh-' . bin2hex(random_bytes(4))],
        )->queryScalar();
        $users = $this->get(UserRepository::class);
        $alice = $users->find(Fixtures::user(['user'])['id']);
        $bob = $users->find(Fixtures::user(['user'])['id']);
        $carol = $users->find(Fixtures::user(['user'], extra: ['tenant_id' => $this->otherTenant])['id']);
        $auditor = $users->find(Fixtures::user(['auditor'])['id']);          // users.login_history, no tenants.cross
        $platformAdmin = $users->find(Fixtures::user(['platform_admin'])['id']); // + tenants.cross

        $t = $this->get(LoginThrottle::class);
        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
        $t->record($alice->username, '10.1.2.3', false, 'bad_password', user: $alice, userAgent: $chrome);
        $t->record($alice->username, '10.1.2.3', true, 'ok', 'password', $alice, $chrome, 'totp');
        $t->record($bob->username, '10.9.9.9', true, 'ok', 'password', $bob);
        $t->record($carol->username, '172.16.0.1', true, 'ok', 'password', $carol);

        $h = $this->get(LoginHistory::class);
        $f = LoginHistory::filters([]);
        $mine = $h->mine($alice, $f, 1);
        self::assertSame(2, $mine->total);
        self::assertSame([$alice->id], array_values(array_unique(array_column($mine->items, 'user_id'))));
        self::assertSame('totp', $mine->items[0]['mfa_method'], 'newest first');
        self::assertSame('10.1.2.3', $mine->items[0]['ip']);
        self::assertSame(1, $h->mine($alice, LoginHistory::filters(['result' => 'failure']), 1)->total);
        self::assertSame(1, $h->stats($alice, $alice->id)['failure']);

        try {
            $h->all($alice, $f, 1);
            self::fail('a regular user must not see the history of others');
        } catch (AccessDeniedException) {
        }
        self::assertFalse($h->mayViewAll($alice));

        $ids = static fn($page) => array_column($page->items, 'user_id');
        $scoped = $h->all($auditor, LoginHistory::filters(['user' => $carol->id]), 1);
        self::assertSame([], $ids($scoped), 'other tenant hidden without tenants.cross');
        self::assertContains($bob->id, $ids($h->all($auditor, LoginHistory::filters(['user' => $bob->username]), 1)));
        self::assertContains($carol->id, $ids($h->all($platformAdmin, LoginHistory::filters(['user' => $carol->id]), 1)));
        self::assertSame([$bob->id], $ids($h->all($platformAdmin, LoginHistory::filters(['ip' => '10.9.9', 'user' => $bob->id]), 1)), 'IP prefix filter');
        self::assertSame([], $ids($h->all($platformAdmin, LoginHistory::filters(['ip' => '10.1.2', 'user' => $bob->id]), 1)));
    }

    public function testFilterSanitisingAndDeviceSummary(): void
    {
        $f = LoginHistory::filters(['result' => 'bogus', 'ip' => "1.2.3.4'; drop", 'from' => '2026-13-99x', 'method' => 'TOTP!', 'user' => str_repeat('a', 300)]);
        self::assertSame('', $f['result']);
        self::assertSame('1.2.3.4d', $f['ip']);
        self::assertSame('', $f['from']);
        self::assertSame('', $f['method']);
        self::assertSame(100, mb_strlen($f['user']));
        self::assertSame('Chrome · Windows', LoginHistory::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'));
        self::assertSame('Safari · iOS', LoginHistory::device('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'));
        self::assertSame('—', LoginHistory::device(null));
    }
}
