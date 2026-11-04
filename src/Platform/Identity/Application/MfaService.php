<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use App\Platform\Identity\Domain\User;
use App\Shared\Infrastructure\Security\Encrypter;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use OTPHP\TOTP;
use SensitiveParameter;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * TOTP (RFC 6238) multi-factor authentication with single-use recovery codes.
 *  - secrets are stored encrypted (XChaCha20-Poly1305),
 *  - replay protection: a time step can be used only once (users.mfa_last_used_step),
 *  - recovery codes are stored as Argon2id hashes and consumed on use.
 */
final class MfaService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const WINDOW = 1; // ±1 step clock drift

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Encrypter $encrypter,
        private readonly string $issuer = 'Yii3 Enterprise Platform',
    ) {}

    public function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public function provisioningUri(string $secret, string $account): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($account);
        $totp->setIssuer($this->issuer);
        return $totp->getProvisioningUri();
    }

    public function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd()));
        return $writer->writeString($uri);
    }

    /**
     * Verifies a code against a plain secret and returns the matched time step (or null).
     */
    public function verifyWithSecret(#[SensitiveParameter] string $secret, string $code, ?int $lastUsedStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $totp = TOTP::createFromSecret($secret);
        $now = time();
        $current = intdiv($now, self::PERIOD);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $current + $offset;
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue; // replay protection
            }
            if (hash_equals($totp->at($step * self::PERIOD), $code)) {
                return $step;
            }
        }
        return null;
    }

    /**
     * Verifies a TOTP or recovery code for a user with MFA enabled; consumes it on success.
     */
    public function verifyForUser(User $user, string $code): bool
    {
        if (!$user->mfaEnabled || $user->mfaSecretEnc === null) {
            return false;
        }
        $secret = $this->encrypter->decrypt($user->mfaSecretEnc, 'mfa');
        $step = $this->verifyWithSecret($secret, $code, $user->mfaLastUsedStep);
        if ($step !== null) {
            // Conditional update closes the race where two requests use the same code concurrently
            $updated = $this->db->createCommand(
                'UPDATE users SET mfa_last_used_step = :step WHERE id = :id AND (mfa_last_used_step IS NULL OR mfa_last_used_step < :step)',
                [':step' => $step, ':id' => $user->id],
            )->execute();
            return $updated === 1;
        }
        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * Enables MFA after the user proved possession of the secret. Returns plain recovery codes (show once).
     *
     * @return list<string>
     */
    public function enable(string $userId, #[SensitiveParameter] string $secret): array
    {
        [$plain, $hashes] = $this->makeRecoveryCodes();
        $this->db->createCommand()->update('users', [
            'mfa_enabled' => true,
            'mfa_secret_enc' => $this->encrypter->encrypt($secret, 'mfa'),
            'mfa_recovery_codes' => json_encode($hashes),
            'mfa_last_used_step' => intdiv(time(), self::PERIOD),
        ], ['id' => $userId])->execute();
        return $plain;
    }

    public function disable(string $userId): void
    {
        $this->db->createCommand()->update('users', [
            'mfa_enabled' => false,
            'mfa_secret_enc' => null,
            'mfa_recovery_codes' => '[]',
            'mfa_last_used_step' => null,
        ], ['id' => $userId])->execute();
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(string $userId): array
    {
        [$plain, $hashes] = $this->makeRecoveryCodes();
        $this->db->createCommand()->update('users', ['mfa_recovery_codes' => json_encode($hashes)], ['id' => $userId])->execute();
        return $plain;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if (strlen($normalized) !== 10) {
            return false;
        }
        foreach ($user->mfaRecoveryCodes as $i => $hash) {
            if (is_string($hash) && password_verify($normalized, $hash)) {
                $remaining = $user->mfaRecoveryCodes;
                unset($remaining[$i]);
                $this->db->createCommand()->update('users', ['mfa_recovery_codes' => json_encode(array_values($remaining))], ['id' => $user->id])->execute();
                return true;
            }
        }
        return false;
    }

    /** @return array{0:list<string>,1:list<string>} */
    private function makeRecoveryCodes(int $count = 10): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[] = substr($c, 0, 5) . '-' . substr($c, 5);
            $hashes[] = password_hash($c, PASSWORD_ARGON2ID);
        }
        return [$plain, $hashes];
    }
}
