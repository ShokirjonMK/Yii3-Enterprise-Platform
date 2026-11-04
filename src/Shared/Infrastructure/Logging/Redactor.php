<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

/**
 * Removes secrets and personal identifiers from anything that is about to be logged, audited or
 * stored as an integration request/response sample.
 */
final class Redactor
{
    public const MASK = '***';

    /** Keys whose values are always masked (case-insensitive substring match). */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey',
        'private_key', 'client_secret', 'refresh', 'otp', 'totp', 'mfa_code', 'pinfl', 'passport',
        'card', 'cvv', 'iban', 'session', 'csrf', 'signature',
    ];

    /** Inline patterns masked inside free-form strings. */
    private const PATTERNS = [
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i' => '$1 ' . self::MASK,
        // Uzbek PINFL: 14 digits
        '/(?<!\d)\d{14}(?!\d)/' => self::MASK,
        // JWT
        '/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/' => self::MASK,
        // Card numbers (13-19 digits, optional separators)
        '/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/' => self::MASK,
        // password=... in query strings / DSNs
        '/((?:password|passwd|pwd|secret|token)=)[^&\s;]+/i' => '$1' . self::MASK,
        // credentials in URLs: scheme://user:pass@
        '#(://[^:/\s]+:)[^@/\s]+@#' => '$1' . self::MASK . '@',
    ];

    public static function isSensitiveKey(string $key): bool
    {
        $k = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($k, $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function redactString(string $value): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[depth-limit]';
        }
        if (is_string($value)) {
            return self::redactString($value);
        }
        if ($value instanceof \Throwable) {
            return [
                'class' => $value::class,
                'message' => self::redactString($value->getMessage()),
                'code' => $value->getCode(),
                'file' => $value->getFile() . ':' . $value->getLine(),
            ];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = is_string($k) && self::isSensitiveKey($k) ? self::MASK : self::redact($v, $depth + 1);
            }
            return $out;
        }
        if (is_object($value)) {
            return $value instanceof \Stringable ? self::redactString((string) $value) : '[object ' . $value::class . ']';
        }
        return $value;
    }
}
