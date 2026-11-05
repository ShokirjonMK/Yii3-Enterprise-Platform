<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

use App\Platform\Identity\Domain\User;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\TooManyRequestsException;
use App\Shared\Infrastructure\Security\Encrypter;
use Predis\ClientInterface;
use SensitiveParameter;
use Throwable;

/**
 * One-time codes for Telegram / e-mail / SMS / voice.
 *
 *  - The code is stored only as an HMAC in Redis with a TTL (mfa.code_ttl_seconds) and is bound to the purpose
 *    (login, enrol, reauth), the user, the method and the delivery target.
 *  - A wrong code increments an attempt counter; after mfa.code_max_attempts the code is burnt.
 *  - Resend cooldown (mfa.resend_seconds) and an hourly cap per user+method (mfa.max_sends_per_hour) protect
 *    against SMS/voice cost abuse ("SMS pumping") and spamming.
 *  - A successful check deletes the code (single use).
 *
 * Author: ShokirjonMK
 */
final class OtpService
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly Encrypter $encrypter,
        private readonly SettingsService $settings,
        private readonly OtpDelivery $delivery,
    ) {}

    /**
     * Generates, stores and delivers a code. Returns the number of seconds until the next send is possible.
     *
     * @throws TooManyRequestsException when sending too often
     * @throws \RuntimeException when the provider fails (the stored code is removed)
     */
    public function send(User $user, string $type, #[SensitiveParameter] string $target, string $purpose): int
    {
        $cooldown = $this->int('mfa.resend_seconds', 60);
        $cdKey = $this->key('cd', $purpose, $user->id, $type);
        $ttlLeft = (int) $this->redis->ttl($cdKey);
        if ($ttlLeft > 0) {
            throw new TooManyRequestsException(sprintf('Please wait %d seconds before requesting a new code.', $ttlLeft), $ttlLeft);
        }
        $hourKey = $this->key('n', 'hour', $user->id, $type);
        $sent = (int) $this->redis->incr($hourKey);
        if ($sent === 1) {
            $this->redis->expire($hourKey, 3600);
        }
        if ($sent > $this->int('mfa.max_sends_per_hour', 10)) {
            throw new TooManyRequestsException('Too many codes were requested. Try again later or use another method.', max(60, (int) $this->redis->ttl($hourKey)));
        }

        $length = max(4, min(8, $this->int('mfa.code_length', 6)));
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        $ttl = $this->int('mfa.code_ttl_seconds', 300);
        $key = $this->key('code', $purpose, $user->id, $type);
        $this->redis->hmset($key, ['h' => $this->hash($code, $target), 'a' => 0]);
        $this->redis->expire($key, $ttl);
        try {
            $this->delivery->deliver($type, $target, $code, intdiv($ttl, 60), $user);
        } catch (Throwable $e) {
            $this->redis->del([$key]);
            throw $e;
        }
        $this->redis->setex($cdKey, $cooldown, '1');
        return $cooldown;
    }

    /** Checks (and on success consumes) a code. */
    public function verify(string $userId, string $type, #[SensitiveParameter] string $target, string $purpose, #[SensitiveParameter] string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $key = $this->key('code', $purpose, $userId, $type);
        $stored = $this->redis->hget($key, 'h');
        if (!is_string($stored) || $code === '') {
            return false;
        }
        $attempts = (int) $this->redis->hincrby($key, 'a', 1);
        if ($attempts > $this->int('mfa.code_max_attempts', 5)) {
            $this->redis->del([$key]);
            return false;
        }
        if (!hash_equals($stored, $this->hash($code, $target))) {
            return false;
        }
        // single use: only the request that deletes the key wins (concurrent submissions of the same code)
        return (int) $this->redis->del([$key]) === 1;
    }

    public function pending(string $userId, string $type, string $purpose): bool
    {
        return (int) $this->redis->exists($this->key('code', $purpose, $userId, $type)) === 1;
    }

    public function cooldown(string $userId, string $type, string $purpose): int
    {
        return max(0, (int) $this->redis->ttl($this->key('cd', $purpose, $userId, $type)));
    }

    public function clear(string $userId, string $type, string $purpose): void
    {
        $this->redis->del([$this->key('code', $purpose, $userId, $type), $this->key('cd', $purpose, $userId, $type)]);
    }

    private function hash(string $code, string $target): string
    {
        return $this->encrypter->hmac($code . '|' . strtolower($target), 'mfa-otp');
    }

    private function key(string $kind, string $purpose, string $userId, string $type): string
    {
        return "mfa:otp:$kind:$purpose:$userId:$type";
    }

    private function int(string $key, int $default): int
    {
        try {
            return (int) ($this->settings->get($key) ?? $default);
        } catch (Throwable) {
            return $default;
        }
    }
}
