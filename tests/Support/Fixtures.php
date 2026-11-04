<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Platform\Access\AccessService;
use App\Platform\Identity\Infrastructure\UserRepository;
use OTPHP\TOTP;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Security\PasswordHasher;

/**
 * Creates isolated test users directly through platform services. Every user is prefixed with
 * "t-" + random suffix and archived in cleanup(), so suites can run against a shared dev database.
 */
final class Fixtures
{
    public const PASSWORD = 'Test-Passw0rd!2026';

    /** @var list<string> */
    private static array $created = [];

    /**
     * @param list<string> $roles
     * @return array{id:string, username:string, password:string, mfa_secret:?string}
     */
    public static function user(array $roles = ['user'], bool $mfa = false, array $extra = []): array
    {
        $c = TestContainer::get();
        /** @var UserRepository $users */
        $users = $c->get(UserRepository::class);
        /** @var PasswordHasher $hasher */
        $hasher = $c->get(PasswordHasher::class);
        /** @var AccessService $access */
        $access = $c->get(AccessService::class);
        /** @var ConnectionInterface $db */
        $db = $c->get(ConnectionInterface::class);

        $username = 't-' . bin2hex(random_bytes(5));
        $tenant = $db->createCommand("SELECT id FROM tenants WHERE code = 'default'")->queryScalar() ?: null;
        $id = $users->insert($extra + [
            'username' => $username,
            'email' => $username . '@test.local',
            'full_name' => 'Test ' . $username,
            'password_hash' => $hasher->hash(self::PASSWORD),
            'password_changed_at' => new Expression('now()'),
            'tenant_id' => $tenant,
            'locale' => 'en', // English UI in tests (assertions use source strings)
        ]);
        foreach ($roles as $role) {
            $access->assignSystem($id, $role);
        }
        $secret = null;
        if ($mfa) {
            $secret = TOTP::generate()->getSecret();
            /** @var \App\Platform\Identity\Application\MfaService $mfaService */
            $mfaService = $c->get(\App\Platform\Identity\Application\MfaService::class);
            $mfaService->enable($id, $secret);
            // enable() marks the current step as used; allow the next login to use the current code
            $db->createCommand()->update('users', ['mfa_last_used_step' => null], ['id' => $id])->execute();
        }
        self::$created[] = $id;
        return ['id' => $id, 'username' => $username, 'password' => self::PASSWORD, 'mfa_secret' => $secret];
    }

    public static function totp(string $secret, int $offsetSteps = 0): string
    {
        return TOTP::createFromSecret($secret)->at(time() + $offsetSteps * 30);
    }

    /**
     * Test runs share one client IP; clear IP-based login throttling so suites stay independent.
     * (Throttling itself is covered by dedicated tests.)
     */
    public static function resetLoginThrottle(): void
    {
        self::db()->createCommand("DELETE FROM login_attempts WHERE created_at > now() - interval '1 day' AND (username LIKE 't-%' OR username LIKE 'nobody-%' OR success = false)")->execute();
        /** @var \Predis\ClientInterface $redis */
        $redis = TestContainer::service(\Predis\ClientInterface::class);
        foreach ($redis->keys('rl:login-ip:*') as $key) {
            // keys() returns prefixed names; strip the client prefix before deleting
            $prefix = (string) $redis->getOptions()->prefix?->getPrefix();
            $redis->del([str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key]);
        }
    }

    /** @var array<string,bool> flags changed by enableFeatures() → original state */
    private static array $flagBackup = [];

    /**
     * Turns optional features on for an HTTP test (they are OFF on fresh installs) and remembers the previous
     * state; restoreFeatures() (call in tearDown) puts it back. Waits for per-process config caches (≤ 2 s).
     *
     * @param list<string> $codes
     */
    public static function enableFeatures(array $codes): void
    {
        $changed = false;
        foreach ($codes as $code) {
            $was = (bool) self::db()->createCommand('SELECT enabled FROM feature_flags WHERE code = :c', [':c' => $code])->queryScalar();
            if (!$was) {
                self::$flagBackup[$code] = false;
                self::db()->createCommand()->update('feature_flags', ['enabled' => true, 'status' => 'enabled'], ['code' => $code])->execute();
                $changed = true;
            }
        }
        if ($changed) {
            TestContainer::service(\App\Platform\Settings\ConfigRevision::class)->bump();
            sleep(3);
        }
    }

    public static function restoreFeatures(): void
    {
        if (self::$flagBackup === []) {
            return;
        }
        foreach (self::$flagBackup as $code => $enabled) {
            self::db()->createCommand()->update('feature_flags', ['enabled' => $enabled, 'status' => $enabled ? 'enabled' : 'disabled'], ['code' => $code])->execute();
        }
        self::$flagBackup = [];
        TestContainer::service(\App\Platform\Settings\ConfigRevision::class)->bump();
    }

    public static function db(): ConnectionInterface
    {
        return TestContainer::service(ConnectionInterface::class);
    }

    public static function cleanup(): void
    {
        if (self::$created === []) {
            return;
        }
        $db = self::db();
        foreach (self::$created as $id) {
            $db->createCommand()->update('users', ['status' => 'archived', 'deleted_at' => new Expression('now()')], ['id' => $id])->execute();
            $db->createCommand()->update('user_sessions', ['revoked_at' => new Expression('now()')], ['user_id' => $id, 'revoked_at' => null])->execute();
            $db->createCommand()->delete('yii_rbac_assignment', ['user_id' => $id])->execute();
        }
        self::$created = [];
    }
}
