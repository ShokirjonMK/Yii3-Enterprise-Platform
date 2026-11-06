<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa\WebAuthn;

use App\Shared\Domain\Exception\ValidationException;
use Throwable;

/**
 * WebAuthn Level 2 relying party (passkeys, FIDO2 security keys, Touch ID / Face ID, Windows Hello, Android).
 *
 * Registration: attestation "none" is requested, so the attestation statement is not evaluated (privacy-friendly,
 * the same choice as most consumer sites); the credential public key is taken from the authenticator data.
 * Verified: ceremony type, challenge (single use, kept by the caller in the session), origin, RP ID hash,
 * user-presence flag, algorithm (ES256 / RS256 / EdDSA) and on sign-in the signature over
 * authenticatorData ‖ SHA-256(clientDataJSON) plus the signature counter (clone detection).
 *
 * Author: ShokirjonMK
 */
final class WebAuthnServer
{
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;
    public const ALG_EDDSA = -8;

    /** @return array{options:array<string,mixed>, challenge:string} */
    public function registrationOptions(string $rpId, string $rpName, string $userId, string $userName, string $displayName, array $excludeIds = []): array
    {
        $challenge = random_bytes(32);
        return ['challenge' => self::b64u($challenge), 'options' => [
            'rp' => ['name' => $rpName, 'id' => $rpId],
            'user' => ['id' => self::b64u(hash('sha256', $userId, true)), 'name' => $userName, 'displayName' => $displayName],
            'challenge' => self::b64u($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => self::ALG_ES256],
                ['type' => 'public-key', 'alg' => self::ALG_EDDSA],
                ['type' => 'public-key', 'alg' => self::ALG_RS256],
            ],
            'timeout' => 120000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
            'excludeCredentials' => array_map(static fn(string $id) => ['type' => 'public-key', 'id' => $id], array_values($excludeIds)),
        ]];
    }

    /** @return array{options:array<string,mixed>, challenge:string} */
    public function assertionOptions(string $rpId, array $credentials): array
    {
        $challenge = random_bytes(32);
        return ['challenge' => self::b64u($challenge), 'options' => [
            'challenge' => self::b64u($challenge),
            'rpId' => $rpId,
            'timeout' => 120000,
            'userVerification' => 'preferred',
            'allowCredentials' => array_map(static fn(array $c) => ['type' => 'public-key', 'id' => $c['id']] + (($c['transports'] ?? []) !== [] ? ['transports' => $c['transports']] : []), array_values($credentials)),
        ]];
    }

    /**
     * @param array<string,mixed> $credential PublicKeyCredential JSON from the browser (base64url fields)
     * @return array{id:string, public_key:string, alg:int, sign_count:int, aaguid:string, transports:list<string>, uv:bool}
     */
    public function verifyRegistration(array $credential, string $challenge, string $origin, string $rpId): array
    {
        try {
            $clientJson = self::d64u((string) ($credential['response']['clientDataJSON'] ?? ''));
            $this->checkClientData($clientJson, 'webauthn.create', $challenge, $origin);
            $att = Cbor::decode(self::d64u((string) ($credential['response']['attestationObject'] ?? '')));
            if (!is_array($att) || !($att['authData'] ?? null) instanceof Bytes) {
                throw new \InvalidArgumentException('attestationObject.authData missing');
            }
            $auth = $this->authData($att['authData']->value, $rpId, true);
            $rawId = self::d64u((string) ($credential['rawId'] ?? $credential['id'] ?? ''));
            if ($rawId === '' || !hash_equals($auth['credential_id'], $rawId)) {
                throw new \InvalidArgumentException('credential id mismatch');
            }
            [$alg, $publicKey] = $this->coseToKey($auth['cose']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::single('credential', 'The security key response is invalid (' . $e->getMessage() . ').');
        }
        $transports = array_values(array_filter(
            (array) ($credential['response']['transports'] ?? $credential['transports'] ?? []),
            static fn($t) => is_string($t) && in_array($t, ['usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card'], true),
        ));
        return [
            'id' => self::b64u($auth['credential_id']),
            'public_key' => $publicKey,
            'alg' => $alg,
            'sign_count' => $auth['sign_count'],
            'aaguid' => bin2hex($auth['aaguid']),
            'transports' => $transports,
            'uv' => $auth['uv'],
        ];
    }

    /**
     * Verifies a sign-in assertion against the stored key; returns the new signature counter.
     *
     * @param array<string,mixed> $credential
     */
    public function verifyAssertion(array $credential, string $challenge, string $origin, string $rpId, string $publicKey, int $alg, int $storedCount): int
    {
        try {
            $clientJson = self::d64u((string) ($credential['response']['clientDataJSON'] ?? ''));
            $this->checkClientData($clientJson, 'webauthn.get', $challenge, $origin);
            $authRaw = self::d64u((string) ($credential['response']['authenticatorData'] ?? ''));
            $auth = $this->authData($authRaw, $rpId, false);
            $signature = self::d64u((string) ($credential['response']['signature'] ?? ''));
            $signed = $authRaw . hash('sha256', $clientJson, true);
            $ok = match ($alg) {
                self::ALG_EDDSA => sodium_crypto_sign_verify_detached($signature, $signed, base64_decode($publicKey, true) ?: ''),
                self::ALG_ES256, self::ALG_RS256 => openssl_verify($signed, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1,
                default => false,
            };
        } catch (Throwable) {
            $ok = false;
            $auth = ['sign_count' => 0];
        }
        if (!$ok) {
            throw ValidationException::single('credential', 'The security key signature is invalid.');
        }
        $count = (int) $auth['sign_count'];
        if (($count !== 0 || $storedCount !== 0) && $count <= $storedCount) {
            throw ValidationException::single('credential', 'This security key reported an old signature counter (possible cloned key). Contact your administrator.');
        }
        return $count;
    }

    public static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function d64u(string $s): string
    {
        $raw = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $raw === false ? '' : $raw;
    }

    private function checkClientData(string $json, string $type, string $challenge, string $origin): void
    {
        $c = json_decode($json, true);
        if (!is_array($c)) {
            throw new \InvalidArgumentException('clientDataJSON is not JSON');
        }
        if (($c['type'] ?? '') !== $type) {
            throw new \InvalidArgumentException('wrong ceremony type');
        }
        if (!is_string($c['challenge'] ?? null) || !hash_equals($challenge, $c['challenge'])) {
            throw new \InvalidArgumentException('challenge mismatch');
        }
        if (!is_string($c['origin'] ?? null) || !hash_equals(rtrim($origin, '/'), rtrim($c['origin'], '/'))) {
            throw new \InvalidArgumentException('origin mismatch');
        }
    }

    /** @return array{sign_count:int, uv:bool, aaguid:string, credential_id:string, cose:array} */
    private function authData(string $raw, string $rpId, bool $withCredential): array
    {
        if (strlen($raw) < 37) {
            throw new \InvalidArgumentException('authenticatorData too short');
        }
        if (!hash_equals(hash('sha256', $rpId, true), substr($raw, 0, 32))) {
            throw new \InvalidArgumentException('RP ID hash mismatch');
        }
        $flags = ord($raw[32]);
        if (($flags & 0x01) === 0) {
            throw new \InvalidArgumentException('user presence flag not set');
        }
        $out = ['sign_count' => unpack('N', substr($raw, 33, 4))[1], 'uv' => ($flags & 0x04) !== 0, 'aaguid' => '', 'credential_id' => '', 'cose' => []];
        if ($withCredential) {
            if (($flags & 0x40) === 0 || strlen($raw) < 55) {
                throw new \InvalidArgumentException('no attested credential data');
            }
            $out['aaguid'] = substr($raw, 37, 16);
            $len = unpack('n', substr($raw, 53, 2))[1];
            $out['credential_id'] = substr($raw, 55, $len);
            if (strlen($out['credential_id']) !== $len || $len === 0) {
                throw new \InvalidArgumentException('bad credential id length');
            }
            $cose = Cbor::decode(substr($raw, 55 + $len));
            if (!is_array($cose)) {
                throw new \InvalidArgumentException('bad COSE key');
            }
            $out['cose'] = $cose;
        }
        return $out;
    }

    /** @return array{0:int,1:string} [alg, PEM public key (or base64 raw Ed25519 key)] */
    private function coseToKey(array $cose): array
    {
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        $b = static fn($v): string => $v instanceof Bytes ? $v->value : throw new \InvalidArgumentException('COSE coordinate missing');
        if ($kty === 2 && $alg === self::ALG_ES256 && ($cose[-1] ?? null) === 1) {
            $x = $b($cose[-2] ?? null);
            $y = $b($cose[-3] ?? null);
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \InvalidArgumentException('bad P-256 point');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            return [self::ALG_ES256, self::pem($der)];
        }
        if ($kty === 3 && $alg === self::ALG_RS256) {
            $n = ltrim($b($cose[-1] ?? null), "\x00");
            $e = ltrim($b($cose[-2] ?? null), "\x00");
            $int = static fn(string $v): string => "\x02" . self::len(strlen((ord($v[0]) & 0x80) ? "\x00" . $v : $v)) . ((ord($v[0]) & 0x80) ? "\x00" . $v : $v);
            $rsaKey = $int($n) . $int($e);
            $rsaKey = "\x30" . self::len(strlen($rsaKey)) . $rsaKey;
            $bitString = "\x03" . self::len(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $spki = $algId . $bitString;
            return [self::ALG_RS256, self::pem("\x30" . self::len(strlen($spki)) . $spki)];
        }
        if ($kty === 1 && $alg === self::ALG_EDDSA && ($cose[-1] ?? null) === 6) {
            $x = $b($cose[-2] ?? null);
            if (strlen($x) !== 32) {
                throw new \InvalidArgumentException('bad Ed25519 key');
            }
            return [self::ALG_EDDSA, base64_encode($x)];
        }
        throw new \InvalidArgumentException('unsupported key type/algorithm');
    }

    private static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $bytes = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
