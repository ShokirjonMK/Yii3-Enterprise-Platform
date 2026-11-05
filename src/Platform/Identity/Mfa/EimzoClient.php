<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

use App\Platform\Settings\SettingsService;
use GuzzleHttp\Client;
use RuntimeException;
use Throwable;

/**
 * E-IMZO-SERVER (Yt-soft / e-imzo.uz) REST client for signature-based authentication:
 *
 *   GET  /frontend/challenge   → {"status":1, "challenge":"…", "ttl":120}
 *   POST /backend/auth         body = PKCS#7 (base64) produced by the E-IMZO desktop app over the challenge,
 *                              headers X-Real-IP (end-user IP) and Host (site domain registered for the API key)
 *                              → {"status":1, "subjectCertificateInfo":{serialNumber, X500Name, subjectName{…}, validFrom, validTo}}
 *
 * E-IMZO-SERVER runs inside the operator's network (it is not a public SaaS) and itself checks the certificate
 * chain, validity and revocation status, and that the challenge was issued by it and not yet used.
 * The identity we bind to is the PINFL (OID 1.2.860.3.16.1.2), falling back to TIN (1.2.860.3.16.1.1) for legal-entity
 * keys, so a renewed certificate of the same person keeps working.
 *
 * Author: ShokirjonMK
 */
final class EimzoClient
{
    public function __construct(private readonly SettingsService $settings) {}

    public function configured(): bool
    {
        return $this->base() !== '';
    }

    public function challenge(): string
    {
        $data = $this->request('GET', '/frontend/challenge');
        $challenge = (string) ($data['challenge'] ?? '');
        if ((int) ($data['status'] ?? 0) !== 1 || $challenge === '') {
            throw new RuntimeException('E-IMZO server did not issue a challenge (status ' . ($data['status'] ?? '?') . ').');
        }
        return $challenge;
    }

    /**
     * @return array{pinfl:string, tin:string, name:string, serial:string, valid_to:string, organization:string}
     */
    public function authenticate(string $pkcs7, string $clientIp, string $host): array
    {
        $pkcs7 = preg_replace('/\s+/', '', $pkcs7) ?? '';
        if ($pkcs7 === '' || strlen($pkcs7) > 200000 || preg_match('#^[A-Za-z0-9+/=]+$#', $pkcs7) !== 1) {
            throw new RuntimeException('The signature is empty or malformed.');
        }
        $data = $this->request('POST', '/backend/auth', $pkcs7, ['X-Real-IP' => $clientIp, 'Host' => $host]);
        if ((int) ($data['status'] ?? 0) !== 1) {
            throw new RuntimeException('E-IMZO rejected the signature: ' . mb_substr((string) ($data['message'] ?? ('status ' . ($data['status'] ?? '?'))), 0, 200));
        }
        $info = (array) ($data['subjectCertificateInfo'] ?? []);
        $subject = (array) ($info['subjectName'] ?? []);
        $x500 = (string) ($info['X500Name'] ?? '');
        $pinfl = (string) ($subject['1.2.860.3.16.1.2'] ?? self::rdn($x500, '1.2.860.3.16.1.2'));
        $tin = (string) ($subject['1.2.860.3.16.1.1'] ?? $subject['UID'] ?? self::rdn($x500, 'UID'));
        if ($pinfl === '' && $tin === '') {
            throw new RuntimeException('The certificate has neither PINFL nor TIN.');
        }
        return [
            'pinfl' => $pinfl,
            'tin' => $tin,
            'name' => (string) ($subject['CN'] ?? self::rdn($x500, 'CN')),
            'serial' => (string) ($info['serialNumber'] ?? ''),
            'valid_to' => (string) ($info['validTo'] ?? ''),
            'organization' => (string) ($subject['O'] ?? self::rdn($x500, 'O')),
        ];
    }

    /** @return array{status:string, details:array<string,mixed>} */
    public function health(): array
    {
        if (!$this->configured()) {
            return ['status' => 'unconfigured', 'details' => ['missing' => 'mfa.eimzo_server_url']];
        }
        try {
            $this->challenge();
            return ['status' => 'healthy', 'details' => ['server' => $this->base()]];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'details' => ['error' => $e->getMessage()]];
        }
    }

    /** Stable identity used for binding: PINFL for individuals, otherwise TIN. */
    public static function identity(array $info): string
    {
        return $info['pinfl'] !== '' ? 'pinfl:' . $info['pinfl'] : 'tin:' . $info['tin'];
    }

    private function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $base = $this->base();
        if ($base === '') {
            throw new RuntimeException('E-IMZO server is not configured.');
        }
        // E-IMZO-SERVER lives in the private network by design, therefore the SSRF guard of the integration
        // HttpClient (which blocks private addresses) is intentionally not used; the URL is admin-controlled.
        $client = new Client(['timeout' => 10, 'connect_timeout' => 3, 'http_errors' => false]);
        try {
            $res = $client->request($method, $base . $path, ['headers' => $headers + ['Accept' => 'application/json'], 'body' => $body]);
        } catch (Throwable $e) {
            throw new RuntimeException('E-IMZO server is unreachable: ' . $e->getMessage(), 0, $e);
        }
        $data = json_decode((string) $res->getBody(), true);
        if (!is_array($data)) {
            throw new RuntimeException('E-IMZO server returned HTTP ' . $res->getStatusCode() . ' without JSON.');
        }
        return $data;
    }

    private function base(): string
    {
        $url = rtrim(trim((string) ($this->settings->get('mfa.eimzo_server_url') ?? '')), '/');
        return preg_match('#^https?://[^\s/]+(:\d+)?(/.*)?$#', $url) === 1 ? $url : '';
    }

    private static function rdn(string $x500, string $attr): string
    {
        foreach (preg_split('/,(?=[A-Za-z0-9.]+=)/', $x500) ?: [] as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            if (strcasecmp(trim($k), $attr) === 0) {
                return trim($v);
            }
        }
        return '';
    }
}
