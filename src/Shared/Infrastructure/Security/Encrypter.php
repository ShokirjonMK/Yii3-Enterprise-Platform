<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated symmetric encryption (libsodium XChaCha20-Poly1305) for secrets stored in the
 * database: MFA seeds, SSO client secrets, integration credentials.
 *
 * Keys are derived from APP_SECRET with BLAKE2b and a purpose label, so rotating APP_SECRET requires
 * re-encryption (`./yii security:rotate-key`). Ciphertext format: "v1:" . base64(nonce || ciphertext).
 */
final class Encrypter
{
    private const VERSION = 'v1:';

    private readonly string $secret;

    public function __construct(#[SensitiveParameter] string $secret)
    {
        $this->secret = $secret;
    }

    public function encrypt(#[SensitiveParameter] string $plaintext, string $purpose = 'default'): string
    {
        $key = $this->key($purpose);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $purpose, $nonce, $key);
        sodium_memzero($key);
        return self::VERSION . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload, string $purpose = 'default'): string
    {
        if (!str_starts_with($payload, self::VERSION)) {
            throw new RuntimeException('Unsupported ciphertext version.');
        }
        $raw = base64_decode(substr($payload, strlen(self::VERSION)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('Malformed ciphertext.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = $this->key($purpose);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $purpose, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key or tampered data).');
        }
        return $plain;
    }

    /**
     * Keyed hash for integrity tags and lookup tokens (hex, 64 chars).
     */
    public function hmac(string $data, string $purpose = 'hmac'): string
    {
        return hash_hmac('sha256', $data, $this->key($purpose));
    }

    private function key(string $purpose): string
    {
        if (strlen($this->secret) < 32) {
            throw new RuntimeException('APP_SECRET is missing or too short (min 32 chars). Run scripts/platform.sh init or set it in the environment.');
        }
        return sodium_crypto_generichash('yep:' . $purpose, $this->secret, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }
}
