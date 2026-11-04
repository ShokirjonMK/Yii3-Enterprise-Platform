<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\Identity\Sso\OidcProvider;
use App\Platform\Identity\Sso\SsoException;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Yiisoft\Cache\ArrayCache;

/**
 * Mock-IdP tests for the OIDC adapter: signature (JWKS), issuer, audience, nonce, expiry, alg confusion,
 * state handling and the complete authorization-code + PKCE flow.
 */
final class OidcIdTokenValidationTest extends TestCase
{
    private const ISSUER = 'https://idp.example.test/realms/corp';
    private const CLIENT = 'platform-client';

    private static string $privateKey;
    private static array $jwk;

    public static function setUpBeforeClass(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        self::$privateKey = $pem;
        $d = openssl_pkey_get_details($res);
        $b64 = static fn(string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        self::$jwk = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e'])];
    }

    private function meta(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/protocol/openid-connect/auth',
            'token_endpoint' => self::ISSUER . '/protocol/openid-connect/token',
            'jwks_uri' => self::ISSUER . '/protocol/openid-connect/certs',
            'code_challenge_methods_supported' => ['S256'],
        ];
    }

    private function provider(array $responses, array &$history = []): OidcProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        return new OidcProvider(new Client(['handler' => $stack]), new ArrayCache());
    }

    private function token(array $override = []): string
    {
        $claims = $override + [
            'iss' => self::ISSUER, 'aud' => self::CLIENT, 'sub' => 'user-123', 'nonce' => 'n-1',
            'iat' => time(), 'exp' => time() + 300, 'email' => 'u@corp.test', 'email_verified' => true, 'name' => 'Test User',
        ];
        return JWT::encode($claims, self::$privateKey, 'RS256', 'k1');
    }

    private function jwksResponse(): Response
    {
        return new Response(200, [], json_encode(['keys' => [self::$jwk]]));
    }

    public function testValidTokenAccepted(): void
    {
        $p = $this->provider([$this->jwksResponse()]);
        $claims = $p->validateIdToken($this->token(), $this->meta(), self::CLIENT, 'n-1');
        self::assertSame('user-123', $claims['sub']);
    }

    /** @return iterable<string, array{array<string,mixed>, string}> */
    public static function badClaims(): iterable
    {
        yield 'wrong issuer' => [['iss' => 'https://evil.test'], 'invalid_issuer'];
        yield 'wrong audience' => [['aud' => 'other-client'], 'invalid_audience'];
        yield 'multi aud without azp' => [['aud' => [self::CLIENT, 'x']], 'invalid_azp'];
        yield 'nonce replay' => [['nonce' => 'other'], 'invalid_nonce'];
        yield 'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], 'invalid_id_token'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badClaims')]
    public function testInvalidClaimsRejected(array $claims, string $reason): void
    {
        $p = $this->provider([$this->jwksResponse(), $this->jwksResponse()]);
        try {
            $p->validateIdToken($this->token($claims), $this->meta(), self::CLIENT, 'n-1');
            self::fail('Token should be rejected');
        } catch (SsoException $e) {
            self::assertSame($reason, $e->reason());
        }
    }

    public function testForgedSignatureRejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $pem);
        $forged = JWT::encode(['iss' => self::ISSUER, 'aud' => self::CLIENT, 'sub' => 'x', 'nonce' => 'n-1', 'exp' => time() + 60], $pem, 'RS256', 'k1');
        $p = $this->provider([$this->jwksResponse(), $this->jwksResponse()]);
        $this->expectException(SsoException::class);
        $p->validateIdToken($forged, $this->meta(), self::CLIENT, 'n-1');
    }

    public function testAlgorithmConfusionRejected(): void
    {
        $hs = JWT::encode(['iss' => self::ISSUER, 'aud' => self::CLIENT, 'sub' => 'x', 'nonce' => 'n-1', 'exp' => time() + 60], str_repeat('shared-secret', 4), 'HS256');
        $p = $this->provider([]);
        $this->expectException(SsoException::class);
        $p->validateIdToken($hs, $this->meta(), self::CLIENT, 'n-1');
    }

    public function testFullAuthorizationCodeFlowWithPkce(): void
    {
        $history = [];
        $p = $this->provider([
            new Response(200, [], json_encode($this->meta())),          // discovery (authorize)
        ], $history);
        $provider = ['code' => 'corp', 'config' => ['issuer' => self::ISSUER, 'client_id' => self::CLIENT], 'secret' => 's3cr3t'];
        $req = $p->authorizationRequest($provider, 'https://app.test/auth/sso/corp/callback');
        parse_str((string) parse_url($req['url'], PHP_URL_QUERY), $q);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertSame($req['state']['state'], $q['state']);
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $req['state']['verifier'], true)), '+/', '-_'), '='), $q['code_challenge']);

        $idToken = JWT::encode(['iss' => self::ISSUER, 'aud' => self::CLIENT, 'sub' => 'user-9', 'nonce' => $req['state']['nonce'], 'exp' => time() + 300, 'email' => 'a@b.c', 'email_verified' => true], self::$privateKey, 'RS256', 'k1');
        $history2 = [];
        $p2 = $this->provider([
            new Response(200, [], json_encode($this->meta())),
            new Response(200, [], json_encode(['id_token' => $idToken, 'access_token' => 'at', 'token_type' => 'Bearer'])),
            $this->jwksResponse(),
        ], $history2);
        $ext = $p2->handleCallback($provider, ['code' => 'abc', 'state' => $req['state']['state']], $req['state'], 'https://app.test/auth/sso/corp/callback');
        self::assertSame('user-9', $ext->subject);
        self::assertSame(self::ISSUER, $ext->issuer);
        self::assertTrue($ext->emailVerified);

        parse_str((string) $history2[1]['request']->getBody(), $form);
        self::assertSame($req['state']['verifier'], $form['code_verifier']);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertStringStartsWith('Basic ', $history2[1]['request']->getHeaderLine('Authorization'));

        $this->expectException(SsoException::class);
        $p2->handleCallback($provider, ['code' => 'abc', 'state' => 'forged'], $req['state'], 'https://app.test/cb');
    }

    public function testInsecureDiscoveryRefused(): void
    {
        $this->expectException(SsoException::class);
        $this->provider([])->discover(['config' => ['issuer' => 'http://idp.local']]);
    }
}
