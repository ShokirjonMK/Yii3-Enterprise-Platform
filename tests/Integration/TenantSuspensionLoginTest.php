<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Identity\Application\AuthenticationService;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;

final class TenantSuspensionLoginTest extends IntegrationTestCase
{
    public function testUsersOfSuspendedTenantCannotAuthenticate(): void
    {
        Fixtures::resetLoginThrottle();
        $tenant = (string) $this->db->createCommand("INSERT INTO tenants (code, name) VALUES (:c, 'Suspended Co') RETURNING id", [':c' => 's' . bin2hex(random_bytes(4))])->queryScalar();
        $u = Fixtures::user(['user'], extra: ['tenant_id' => $tenant]);
        $auth = $this->get(AuthenticationService::class);

        self::assertSame($u['id'], $auth->authenticate($u['username'], $u['password'])->user->id, 'active tenant: ok');

        $this->db->createCommand()->update('tenants', ['status' => 'suspended'], ['id' => $tenant])->execute();
        $this->expectException(ValidationException::class);
        $auth->authenticate($u['username'], $u['password']);
    }
}
