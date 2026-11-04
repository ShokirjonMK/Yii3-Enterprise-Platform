<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Identity\Application\PasswordPolicy;
use App\Shared\Infrastructure\Http\ClientIp;
use App\Shared\Infrastructure\Logging\Redactor;
use App\Shared\Infrastructure\Security\Encrypter;
use App\Web\Middleware\RequireLogin;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityPrimitivesTest extends TestCase
{
    public function testEncrypterRoundTripAndTamperDetection(): void
    {
        $e = new Encrypter(str_repeat('k', 64));
        $c = $e->encrypt('secret-value', 'mfa');
        self::assertStringStartsWith('v1:', $c);
        self::assertSame('secret-value', $e->decrypt($c, 'mfa'));
        self::assertNotSame($c, $e->encrypt('secret-value', 'mfa'), 'random nonce per encryption');

        $this->expectException(\RuntimeException::class);
        $e->decrypt($c, 'other-purpose'); // purpose is bound as associated data
    }

    public function testEncrypterRejectsModifiedCiphertext(): void
    {
        $e = new Encrypter(str_repeat('k', 64));
        $raw = base64_decode(substr($e->encrypt('x', 'p'), 3));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->expectException(\RuntimeException::class);
        $e->decrypt('v1:' . base64_encode($raw), 'p');
    }

    public function testEncrypterRequiresSecret(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Encrypter('short'))->encrypt('x');
    }

    #[DataProvider('passwords')]
    public function testPasswordPolicy(string $password, bool $valid): void
    {
        self::assertSame($valid, (new PasswordPolicy())->violations($password, 'johndoe') === [], $password);
    }

    public static function passwords(): iterable
    {
        yield ['Short1!', false];
        yield ['alllowercase123!', false];
        yield ['NoDigitsHere!!!', false];
        yield ['NoSymbols12345A', false];
        yield ['Johndoe-12345!', false];      // contains username
        yield ['P@ssw0rd', false];            // common + short
        yield ['Correct-Horse-9-Battery', true];
        yield ['Ünïcode-Pässwörd-42', true];
    }

    public function testGeneratedPasswordsSatisfyPolicy(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertSame([], (new PasswordPolicy())->violations(PasswordPolicy::generate()));
        }
    }

    public function testRedactorMasksSecretsAndPinfl(): void
    {
        $r = Redactor::redact([
            'password' => 'hunter2',
            'nested' => ['client_secret' => 'abc', 'note' => 'PINFL 31234567890123 and card 4111 1111 1111 1111'],
            'auth' => 'Bearer eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl',
            'dsn' => 'pgsql://user:topsecret@db/x',
            'ok' => 'plain text',
        ]);
        self::assertSame('***', $r['password']);
        self::assertSame('***', $r['nested']['client_secret']);
        self::assertStringNotContainsString('31234567890123', $r['nested']['note']);
        self::assertStringNotContainsString('4111', $r['nested']['note']);
        self::assertStringNotContainsString('eyJ', $r['auth']);
        self::assertStringNotContainsString('topsecret', $r['dsn']);
        self::assertSame('plain text', $r['ok']);
    }

    public function testClientIpIgnoresSpoofedForwardedForFromUntrustedPeer(): void
    {
        $ip = new ClientIp(['10.0.0.0/8']);
        $req = (new ServerRequest(['REMOTE_ADDR' => '203.0.113.5']))->withHeader('X-Forwarded-For', '1.2.3.4');
        self::assertSame('203.0.113.5', $ip->resolve($req));

        $req = (new ServerRequest(['REMOTE_ADDR' => '10.1.2.3']))->withHeader('X-Forwarded-For', '198.51.100.7, 10.0.0.9');
        self::assertSame('198.51.100.7', $ip->resolve($req));
        self::assertTrue(ClientIp::inCidr('192.168.1.10', '192.168.0.0/16'));
        self::assertFalse(ClientIp::inCidr('192.169.1.10', '192.168.0.0/16'));
        self::assertTrue(ClientIp::inCidr('2001:db8::1', '2001:db8::/32'));
    }

    #[DataProvider('returnUrls')]
    public function testOpenRedirectProtection(?string $url, ?string $expected): void
    {
        self::assertSame($expected, RequireLogin::safeReturn($url));
    }

    public static function returnUrls(): iterable
    {
        yield ['/admin/users?page=2', '/admin/users?page=2'];
        yield ['//evil.example.com', null];
        yield ['https://evil.example.com', null];
        yield ['/\\evil.example.com', null];
        yield ["/ok\r\nLocation: x", null];
        yield ['', null];
        yield [null, null];
    }
}
