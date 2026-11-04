<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Webhook;

use SensitiveParameter;

/**
 * HMAC-SHA256 webhook signatures over "<unix timestamp>.<raw body>" (Stripe-style), used for inbound
 * verification and outbound signing.
 *
 *   X-Timestamp: 1767225600
 *   X-Signature: sha256=<hex hmac>
 *
 * The timestamp is part of the signed material, so a captured request cannot be replayed outside the tolerance
 * window; inside the window replays are stopped by the (endpoint, external id) idempotency key.
 */
final class WebhookSigner
{
    public static function sign(#[SensitiveParameter] string $secret, int|string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Headers for an outbound signed request.
     *
     * @return array<string,string>
     */
    public static function headers(#[SensitiveParameter] string $secret, string $body, ?int $timestamp = null, string $signatureHeader = 'X-Signature', string $timestampHeader = 'X-Timestamp'): array
    {
        $ts = $timestamp ?? time();
        return [$timestampHeader => (string) $ts, $signatureHeader => 'sha256=' . self::sign($secret, $ts, $body)];
    }

    /**
     * Constant-time verification. Accepts "sha256=<hex>", "v1=<hex>" or bare hex, and multiple comma/space
     * separated candidates (secret rotation on the sender side).
     *
     * @return string|null null when valid, otherwise the failure reason (for audit, never shown to the sender)
     */
    public static function verify(#[SensitiveParameter] string $secret, ?string $timestamp, string $body, ?string $signature, int $toleranceSeconds = 300, ?int $now = null): ?string
    {
        if ($timestamp === null || $timestamp === '' || !ctype_digit($timestamp)) {
            return 'missing_or_invalid_timestamp';
        }
        $now ??= time();
        if (abs($now - (int) $timestamp) > $toleranceSeconds) {
            return 'timestamp_outside_tolerance';
        }
        if ($signature === null || trim($signature) === '') {
            return 'missing_signature';
        }
        $expected = self::sign($secret, $timestamp, $body);
        $valid = false;
        foreach (preg_split('/[\s,]+/', trim($signature)) ?: [] as $candidate) {
            $hex = strtolower(preg_replace('/^(sha256|v1)=/i', '', $candidate) ?? '');
            // evaluate every candidate (no early exit) to keep timing independent of position
            $valid = hash_equals($expected, $hex) || $valid;
        }
        return $valid ? null : 'signature_mismatch';
    }

    public static function generateSecret(): string
    {
        return 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
