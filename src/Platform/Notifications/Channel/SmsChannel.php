<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Channel;

use App\Platform\Integrations\Exception\IntegrationException;
use App\Platform\Integrations\Http\HttpClient;
use App\Platform\Integrations\Http\IntegrationConfig;
use App\Platform\Notifications\NotificationChannelInterface;
use App\Platform\Queue\PermanentJobFailure;
use App\Platform\Security\UrlGuard;
use App\Platform\Settings\SettingsService;
use Throwable;

/**
 * Generic HTTPS SMS gateway: POST <notifications.sms_gateway_url> with JSON {to, text, from} and
 * "Authorization: Bearer <notifications.sms_gateway_token>". The provider message id is read from
 * id | message_id | messageId | data.id of the JSON response. Calls go through the platform HttpClient
 * (SSRF guard, breaker "sms", logs with masked token). POST is not retried by the client — the job retries.
 */
final class SmsChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly HttpClient $http,
        private readonly UrlGuard $guard,
    ) {}

    public function code(): string
    {
        return 'sms';
    }

    public function send(array $notification): ?string
    {
        [$url, $token, $sender] = $this->config();
        if ($url === '' || $token === '') {
            throw new PermanentJobFailure('SMS gateway is not configured (notifications.sms_gateway_url/token).');
        }
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new PermanentJobFailure('SMS gateway URL must use HTTPS.');
        }
        $to = preg_replace('/[\s()-]/', '', (string) $notification['recipient']) ?? '';
        if (preg_match('/^\+?[1-9]\d{6,14}$/', $to) !== 1) {
            throw new PermanentJobFailure('Invalid phone number.');
        }
        $payload = ['to' => $to, 'text' => mb_substr(trim((string) $notification['body']), 0, 1000)];
        if ($sender !== '') {
            $payload['from'] = $sender;
        }
        $cfg = new IntegrationConfig(code: 'sms', baseUrl: $url, authType: 'bearer', secret: $token, timeoutMs: 10000, maxRetries: 0, status: 'healthy');
        try {
            $data = $this->http->sendJson($cfg, 'POST', $url, ['json' => $payload, 'secrets' => array_values(array_filter((array) ($notification['secrets'] ?? []), 'is_string'))]);
        } catch (IntegrationException $e) {
            if (!$e->isRetryable() && !in_array($e->errorClass(), ['dns', 'connect', 'network'], true)) {
                throw new PermanentJobFailure($e->getMessage(), 0, $e);
            }
            throw $e;
        }
        $id = $data['id'] ?? $data['message_id'] ?? $data['messageId'] ?? ($data['data']['id'] ?? null);
        return $id === null ? null : mb_substr((string) $id, 0, 255);
    }

    /**
     * A generic gateway has no standard health endpoint: "healthy" means configured, HTTPS, public (SSRF guard)
     * and TLS-reachable. Actual delivery is verified by a test send from Admin → Notifications.
     */
    public function health(): array
    {
        [$url, $token] = $this->config();
        if ($url === '' || $token === '') {
            return ['status' => 'unconfigured', 'details' => ['missing' => $url === '' ? 'notifications.sms_gateway_url' : 'notifications.sms_gateway_token']];
        }
        if (!str_starts_with(strtolower($url), 'https://')) {
            return ['status' => 'failed', 'details' => ['error' => 'gateway URL must use HTTPS']];
        }
        try {
            $t = $this->guard->assertAllowed($url);
            $fp = @fsockopen('tls://' . $t['host'], $t['port'], $errno, $err, 3);
            if ($fp === false) {
                return ['status' => 'failed', 'details' => ['error' => "TLS connect failed: $err"]];
            }
            fclose($fp);
            return ['status' => 'healthy', 'details' => ['host' => $t['host'], 'note' => 'reachable; delivery verified only by a test send']];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'details' => ['error' => $e->getMessage()]];
        }
    }

    /** @return array{0:string,1:string,2:string} */
    private function config(): array
    {
        return [
            trim((string) ($this->settings->get('notifications.sms_gateway_url') ?? '')),
            trim((string) ($this->settings->get('notifications.sms_gateway_token') ?? '')),
            trim((string) ($this->settings->get('notifications.sms_sender') ?? '')),
        ];
    }
}
