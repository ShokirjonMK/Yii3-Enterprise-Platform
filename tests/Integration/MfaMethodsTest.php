<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Identity\Mfa\MfaManager;
use App\Platform\Identity\Mfa\MfaMethodRepository;
use App\Platform\Identity\Mfa\MfaMethodType;
use App\Platform\Identity\Mfa\MfaPolicyService;
use App\Platform\Identity\Mfa\OtpService;
use App\Platform\Identity\Mfa\TelegramLink;
use App\Platform\Identity\Mfa\TrustedDevices;
use App\Platform\Identity\Mfa\WebAuthn\WebAuthnServer;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use Predis\ClientInterface;

/**
 * Multi-method two-factor: one-time codes, role policies, Telegram linking, passkeys (real ES256 signatures),
 * remembered devices, last-method protection.
 */
final class MfaMethodsTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        $db = Fixtures::db();
        $db->createCommand()->delete('mfa_policies', ['subject' => ['auditor', 'developer']])->execute();
        Fixtures::cleanup();
        parent::tearDown();
    }

    public function testOneTimeCodeIsSingleUseBoundToTargetAndBurntAfterAttempts(): void
    {
        $u = Fixtures::user(['user']);
        $user = $this->get(UserRepository::class)->find($u['id']);
        /** @var OtpService $otp */
        $otp = $this->get(OtpService::class);
        /** @var ClientInterface $redis */
        $redis = $this->get(ClientInterface::class);
        $key = "mfa:otp:code:enrol:{$user->id}:email";
        // store a known code the same way send() does (delivery is tested end-to-end with Mailpit)
        $hash = $this->get(\App\Shared\Infrastructure\Security\Encrypter::class)->hmac('123456|a@example.com', 'mfa-otp');
        $redis->hmset($key, ['h' => $hash, 'a' => 0]);
        $redis->expire($key, 60);

        self::assertFalse($otp->verify($user->id, 'email', 'other@example.com', 'enrol', '123456'), 'bound to the target');
        self::assertFalse($otp->verify($user->id, 'email', 'a@example.com', 'login', '123456'), 'bound to the purpose');
        self::assertTrue($otp->verify($user->id, 'email', 'a@example.com', 'enrol', '123456'));
        self::assertFalse($otp->verify($user->id, 'email', 'a@example.com', 'enrol', '123456'), 'single use');

        $redis->hmset($key, ['h' => $hash, 'a' => 0]);
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($otp->verify($user->id, 'email', 'a@example.com', 'enrol', '000000'));
        }
        self::assertFalse($otp->verify($user->id, 'email', 'a@example.com', 'enrol', '123456'), 'burnt after max attempts');
    }

    public function testPolicyMergingAcrossRoles(): void
    {
        /** @var MfaPolicyService $policies */
        $policies = $this->get(MfaPolicyService::class);
        $admin = Fixtures::user(['superadmin']);
        $policies->save($admin['id'], 'auditor', true, ['totp', 'webauthn', 'email'], 14);
        $policies->save($admin['id'], 'developer', false, ['totp', 'email'], 7);

        $u = Fixtures::user(['auditor', 'developer']);
        $p = $policies->forUser($this->get(UserRepository::class)->find($u['id']));
        self::assertTrue($p->required, 'required when any role requires it');
        self::assertSame(['totp', 'email'], $p->allowed, 'intersection, filtered by enabled+configured methods');
        self::assertSame(7, $p->rememberDays, 'shortest remember period');

        $plain = Fixtures::user(['user']);
        $d = $policies->forUser($this->get(UserRepository::class)->find($plain['id']));
        self::assertFalse($d->required);
        self::assertSame(['*'], $d->sources);

        $super = $policies->forUser($this->get(UserRepository::class)->find($admin['id']));
        self::assertTrue($super->required, 'seeded policy: administrators must use 2FA');
        self::assertNotContains('sms', $super->allowed);
    }

    public function testOnlySuperadminEditsAdministrativeRolePolicy(): void
    {
        $pa = Fixtures::user(['platform_admin']);
        $this->expectException(AccessDeniedException::class);
        $this->get(MfaPolicyService::class)->save($pa['id'], 'superadmin', false, ['email'], 30);
    }

    public function testTelegramLinkBindsPrivateChatOnce(): void
    {
        $u = Fixtures::user(['user']);
        /** @var ClientInterface $redis */
        $redis = $this->get(ClientInterface::class);
        $token = 'tok_' . bin2hex(random_bytes(10));
        $redis->setex("mfa:tg:link:$token", 60, $u['id']);
        $redis->setex("mfa:tg:user:{$u['id']}", 60, $token);
        /** @var TelegramLink $link */
        $link = $this->get(TelegramLink::class);

        $link->handle(['message' => ['text' => "/start $token", 'chat' => ['id' => -100123, 'type' => 'group'], 'from' => ['id' => 5]]]);
        self::assertNull($this->get(MfaMethodRepository::class)->findByType($u['id'], 'telegram'), 'groups are refused');

        $link->handle(['message' => ['text' => "/start $token", 'chat' => ['id' => 777001, 'type' => 'private'], 'from' => ['id' => 777001, 'username' => 'tester']]]);
        $m = $this->get(MfaMethodRepository::class)->findByType($u['id'], 'telegram');
        self::assertNotNull($m);
        self::assertSame('777001', $this->get(MfaMethodRepository::class)->target($m));
        self::assertSame('@tester', $m['label']);
        self::assertNull($redis->get("mfa:tg:link:$token"), 'token is single use');
        self::assertSame($u['id'], $this->get(MfaMethodRepository::class)->ownerOf('telegram', '777001'));

        $codes = $this->get(MfaManager::class)->added($this->get(UserRepository::class)->find($u['id']), 'telegram');
        self::assertCount(10, (array) $codes, 'first factor creates recovery codes');
        self::assertTrue($this->get(UserRepository::class)->find($u['id'])->mfaEnabled);
    }

    public function testPasskeyRegistrationAndAssertionWithRealEs256Key(): void
    {
        $u = Fixtures::user(['user']);
        $user = $this->get(UserRepository::class)->find($u['id']);
        /** @var MfaManager $mfa */
        $mfa = $this->get(MfaManager::class);
        $rpId = 'localhost';
        $origin = 'http://localhost:8090';

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key)['ec'];
        $credId = random_bytes(16);
        $cose = Cbor::map([1 => 2, 3 => -7, -1 => 1, -2 => Cbor::bytes(str_pad($d['x'], 32, "\0", STR_PAD_LEFT)), -3 => Cbor::bytes(str_pad($d['y'], 32, "\0", STR_PAD_LEFT))]);

        $reg = $mfa->passkeyRegistration($user, $rpId, 'Test');
        $authData = hash('sha256', $rpId, true) . chr(0x41 | 0x04) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . $cose;
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => $reg['challenge'], 'origin' => $origin]);
        $credential = ['id' => WebAuthnServer::b64u($credId), 'rawId' => WebAuthnServer::b64u($credId), 'response' => [
            'clientDataJSON' => WebAuthnServer::b64u($client),
            'attestationObject' => WebAuthnServer::b64u(Cbor::map(['fmt' => Cbor::text('none'), 'attStmt' => Cbor::map([]), 'authData' => Cbor::bytes($authData)])),
            'transports' => ['internal'],
        ]];
        // wrong origin is refused
        try {
            $mfa->registerPasskey($user, $credential, $reg['challenge'], 'https://evil.example', $rpId, 'x');
            self::fail('origin mismatch accepted');
        } catch (ValidationException) {
        }
        $codes = $mfa->registerPasskey($user, $credential, $reg['challenge'], $origin, $rpId, 'Laptop');
        self::assertCount(10, (array) $codes);
        $user = $this->get(UserRepository::class)->find($u['id']);

        $sign = function (int $count, string $challenge) use ($rpId, $origin, $key, $credId): array {
            $auth = hash('sha256', $rpId, true) . chr(0x05) . pack('N', $count);
            $cj = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin]);
            openssl_sign($auth . hash('sha256', $cj, true), $sig, $key, OPENSSL_ALGO_SHA256);
            return ['id' => WebAuthnServer::b64u($credId), 'response' => ['clientDataJSON' => WebAuthnServer::b64u($cj), 'authenticatorData' => WebAuthnServer::b64u($auth), 'signature' => WebAuthnServer::b64u($sig)]];
        };
        $a = $mfa->passkeyAssertion($user, $rpId);
        self::assertTrue($mfa->verify($user, 'webauthn', ['credential' => $sign(5, $a['challenge']), 'challenge' => $a['challenge'], 'origin' => $origin, 'rp_id' => $rpId]));
        $a2 = $mfa->passkeyAssertion($user, $rpId);
        self::assertFalse($mfa->verify($user, 'webauthn', ['credential' => $sign(5, $a2['challenge']), 'challenge' => $a2['challenge'], 'origin' => $origin, 'rp_id' => $rpId]), 'counter must grow (clone detection)');
        self::assertFalse($mfa->verify($user, 'webauthn', ['credential' => $sign(9, 'other-challenge'), 'challenge' => $a2['challenge'], 'origin' => $origin, 'rp_id' => $rpId]), 'challenge bound');
        self::assertTrue($mfa->verify($user, 'webauthn', ['credential' => $sign(9, $a2['challenge']), 'challenge' => $a2['challenge'], 'origin' => $origin, 'rp_id' => $rpId]));
    }

    public function testRequiredPolicyKeepsLastAllowedMethodAndTrustedDevices(): void
    {
        $u = Fixtures::user(['security_admin'], mfa: true);
        $user = $this->get(UserRepository::class)->find($u['id']);
        try {
            $this->get(MfaManager::class)->remove($user, 'totp');
            self::fail('last method of a mandatory policy removed');
        } catch (ConflictException $e) {
            self::assertSame('mfa_last_method', $e->errorCode());
        }

        /** @var TrustedDevices $devices */
        $devices = $this->get(TrustedDevices::class);
        $token = $devices->issue($u['id'], 30, 'phpunit', '127.0.0.1');
        self::assertTrue($devices->isTrusted($u['id'], $token, 30));
        self::assertFalse($devices->isTrusted($u['id'], $token, 0), 'policy without remembering ignores the cookie');
        self::assertFalse($devices->isTrusted(Fixtures::user(['user'])['id'], $token, 30), 'bound to the user');
        $this->get(MfaManager::class)->resetAll($u['id']);
        self::assertFalse($devices->isTrusted($u['id'], $token, 30), 'reset forgets devices');
        self::assertFalse($this->get(UserRepository::class)->find($u['id'])->mfaEnabled);
    }

    public function testMaskingAndPhoneNormalisation(): void
    {
        self::assertSame('+998901234567', MfaMethodType::normalizePhone('+998 (90) 123-45-67'));
        self::assertSame('+998901234567', MfaMethodType::normalizePhone('00998901234567'));
        self::assertNull(MfaMethodType::normalizePhone('12'));
        self::assertSame('+99890*****67', MfaMethodType::mask('sms', '+998901234567'));
        self::assertSame('j***@example.com', MfaMethodType::mask('email', 'john@example.com'));
    }
}

/** Tiny CBOR encoder for building authenticator test vectors. */
final class Cbor
{
    public static function head(int $major, int $len): string
    {
        return match (true) {
            $len < 24 => chr(($major << 5) | $len),
            $len < 256 => chr(($major << 5) | 24) . chr($len),
            default => chr(($major << 5) | 25) . pack('n', $len),
        };
    }

    public static function int(int $v): string
    {
        return $v >= 0 ? self::head(0, $v) : self::head(1, -1 - $v);
    }

    public static function bytes(string $b): string
    {
        return self::head(2, strlen($b)) . $b;
    }

    public static function text(string $t): string
    {
        return self::head(3, strlen($t)) . $t;
    }

    /** @param array<int|string,string|int> $m values: already-encoded strings or ints */
    public static function map(array $m): string
    {
        $out = self::head(5, count($m));
        foreach ($m as $k => $v) {
            $out .= is_int($k) ? self::int($k) : self::text($k);
            $out .= is_int($v) ? self::int($v) : $v;
        }
        return $out;
    }
}
