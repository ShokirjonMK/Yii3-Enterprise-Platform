<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

/**
 * Second-factor method catalogue.
 *  - totp      authenticator app (Google/Microsoft Authenticator, Aegis…) — secret in users.mfa_secret_enc
 *  - webauthn  passkeys / FIDO2 security keys / Touch ID / Windows Hello — phishing resistant
 *  - eimzo     E-IMZO qualified electronic signature (Uzbekistan) — challenge signed by the user's key
 *  - telegram  one-time code sent by the platform Telegram bot (linked with one button)
 *  - email     one-time code to a verified e-mail address
 *  - sms       one-time code by SMS to a verified phone
 *  - voice     a phone call that reads the one-time code (text-to-speech)
 *
 * Author: ShokirjonMK
 */
final class MfaMethodType
{
    public const TOTP = 'totp';
    public const WEBAUTHN = 'webauthn';
    public const EIMZO = 'eimzo';
    public const TELEGRAM = 'telegram';
    public const EMAIL = 'email';
    public const SMS = 'sms';
    public const VOICE = 'voice';

    /** Strongest first — also the default display order. */
    public const ALL = [self::WEBAUTHN, self::TOTP, self::EIMZO, self::TELEGRAM, self::EMAIL, self::SMS, self::VOICE];

    /** Methods where the platform sends a one-time code. */
    public const OTP = [self::TELEGRAM, self::EMAIL, self::SMS, self::VOICE];

    public const LABELS = [
        self::TOTP => 'Authenticator app',
        self::WEBAUTHN => 'Passkey / security key',
        self::EIMZO => 'E-IMZO digital signature',
        self::TELEGRAM => 'Telegram',
        self::EMAIL => 'E-mail',
        self::SMS => 'SMS',
        self::VOICE => 'Voice call',
    ];

    public const ICONS = [
        self::TOTP => 'key',
        self::WEBAUTHN => 'shield',
        self::EIMZO => 'file',
        self::TELEGRAM => 'bell',
        self::EMAIL => 'globe',
        self::SMS => 'activity',
        self::VOICE => 'bolt',
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    public static function isOtp(string $type): bool
    {
        return in_array($type, self::OTP, true);
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    /** "+998 90 *** ** 12", "a***@example.com" */
    public static function mask(string $type, string $target): string
    {
        if ($type === self::EMAIL && str_contains($target, '@')) {
            [$local, $domain] = explode('@', $target, 2);
            return mb_substr($local, 0, 1) . str_repeat('*', max(2, mb_strlen($local) - 1)) . '@' . $domain;
        }
        if (in_array($type, [self::SMS, self::VOICE], true)) {
            $digits = preg_replace('/\D/', '', $target) ?? '';
            return '+' . substr($digits, 0, 5) . str_repeat('*', max(0, strlen($digits) - 7)) . substr($digits, -2);
        }
        return $target;
    }

    /** Normalises a phone number to E.164 (+998901234567) or returns null. */
    public static function normalizePhone(string $phone): ?string
    {
        $p = preg_replace('/[\s().-]/', '', trim($phone)) ?? '';
        if (str_starts_with($p, '00')) {
            $p = '+' . substr($p, 2);
        }
        if (!str_starts_with($p, '+')) {
            $p = '+' . $p;
        }
        return preg_match('/^\+[1-9]\d{7,14}$/', $p) === 1 ? $p : null;
    }
}
