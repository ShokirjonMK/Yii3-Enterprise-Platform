<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

use App\Platform\Integrations\Http\HttpClient;
use App\Platform\Integrations\Http\IntegrationConfig;
use App\Platform\Settings\SettingsService;
use RuntimeException;
use SensitiveParameter;

/**
 * Places a phone call that reads the one-time code aloud (text-to-speech), twice.
 *
 * Providers (setting mfa.voice_provider):
 *  - twilio  Twilio Programmable Voice: POST /2010-04-01/Accounts/{SID}/Calls.json with inline TwiML <Say>
 *  - http    any HTTPS voice/OTP gateway: POST <mfa.voice_http_url> JSON {to, code, text, language}
 *            with "Authorization: Bearer <mfa.voice_http_token>" (e.g. a local telecom operator's call API)
 * Calls go through the platform HttpClient (SSRF guard, timeouts, breaker "voice", masked secrets in logs).
 *
 * Author: ShokirjonMK
 */
final class VoiceCallClient
{
    private const PHRASE = [
        'ru-RU' => ['Ваш код подтверждения', 'Повторяю'],
        'en-US' => ['Your verification code is', 'Again'],
        'tr-TR' => ['Doğrulama kodunuz', 'Tekrar ediyorum'],
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly HttpClient $http,
    ) {}

    /** @return string provider call id */
    public function call(string $phone, #[SensitiveParameter] string $code): string
    {
        $to = MfaMethodType::normalizePhone($phone) ?? throw new RuntimeException('Invalid phone number.');
        $language = (string) ($this->settings->get('mfa.voice_language') ?: 'ru-RU');
        [$intro, $again] = self::PHRASE[$language] ?? self::PHRASE['en-US'];
        $spoken = implode(', ', str_split($code));
        $text = "$intro: $spoken. $again: $spoken.";

        return match ((string) $this->settings->get('mfa.voice_provider')) {
            'twilio' => $this->twilio($to, $text, $language, [$code, $spoken, urlencode($spoken), rawurlencode($spoken)]),
            'http' => $this->gateway($to, $code, $text, $language, $spoken),
            default => throw new RuntimeException('Voice call provider is not configured.'),
        };
    }

    private function twilio(string $to, string $text, string $language, array $mask): string
    {
        $sid = trim((string) $this->settings->get('mfa.voice_twilio_sid'));
        $token = trim((string) ($this->settings->get('mfa.voice_twilio_token') ?? ''));
        $from = MfaMethodType::normalizePhone((string) $this->settings->get('mfa.voice_from'));
        if (preg_match('/^AC[0-9a-f]{32}$/i', $sid) !== 1 || $token === '' || $from === null) {
            throw new RuntimeException('Twilio is not configured (Account SID, Auth token, caller number).');
        }
        $say = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $twiml = '<Response><Say language="' . $language . '">' . $say . '</Say><Pause length="1"/><Say language="' . $language . '">' . $say . '</Say></Response>';
        $cfg = new IntegrationConfig(
            code: 'voice',
            baseUrl: 'https://api.twilio.com',
            authType: 'basic',
            secret: $token,
            authConfig: ['username' => $sid],
            timeoutMs: 10000,
            maxRetries: 0,
            status: 'healthy',
        );
        $data = $this->http->sendJson($cfg, 'POST', "/2010-04-01/Accounts/$sid/Calls.json", [
            'form_params' => ['To' => $to, 'From' => $from, 'Twiml' => $twiml, 'Timeout' => 30],
            'secrets' => $mask,
        ]);
        return (string) ($data['sid'] ?? '');
    }

    private function gateway(string $to, #[SensitiveParameter] string $code, string $text, string $language, string $spoken): string
    {
        $url = trim((string) $this->settings->get('mfa.voice_http_url'));
        $token = trim((string) ($this->settings->get('mfa.voice_http_token') ?? ''));
        if ($url === '' || $token === '') {
            throw new RuntimeException('Voice gateway is not configured (URL and token).');
        }
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('Voice gateway URL must use HTTPS.');
        }
        $cfg = new IntegrationConfig(code: 'voice', baseUrl: $url, authType: 'bearer', secret: $token, timeoutMs: 10000, maxRetries: 0, status: 'healthy');
        $data = $this->http->sendJson($cfg, 'POST', $url, [
            'json' => ['to' => $to, 'code' => $code, 'text' => $text, 'language' => $language],
            'secrets' => [$code, $spoken],
        ]);
        return (string) ($data['id'] ?? $data['call_id'] ?? $data['sid'] ?? '');
    }
}
