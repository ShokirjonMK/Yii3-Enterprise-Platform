<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure;

use App\Shared\Infrastructure\Logging\Redactor;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;

/**
 * Tiny JSON-over-HTTP helper shared by the operations API clients. Builds a Guzzle client with
 * strict timeouts (an unreachable dependency must never hang an admin page) and converts transport
 * errors and non-2xx responses into {@see ExternalServiceException} with redacted messages.
 */
final class JsonHttp
{
    private ClientInterface $http;

    /**
     * @param callable|null $handler Guzzle handler (tests inject a MockHandler stack)
     * @param bool|string $verify TLS verification: true, false or a CA bundle path
     * @param array<string,string> $headers default headers (credentials)
     */
    public function __construct(
        private readonly string $service,
        string $baseUri,
        array $headers = [],
        bool|string $verify = true,
        float $timeout = 5.0,
        ?callable $handler = null,
    ) {
        $options = [
            'base_uri' => rtrim($baseUri, '/') . '/',
            'headers' => $headers + ['Accept' => 'application/json', 'User-Agent' => 'yii3-platform-ops/1.0'],
            'verify' => $verify,
            'timeout' => $timeout,
            'connect_timeout' => min(2.0, $timeout),
            'http_errors' => false,
            'allow_redirects' => false,
        ];
        if ($handler !== null) {
            $options['handler'] = $handler instanceof HandlerStack ? $handler : HandlerStack::create($handler);
        }
        $this->http = new Client($options);
    }

    /**
     * @param array<string,mixed> $options Guzzle request options (query, json, body, headers)
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (GuzzleException $e) {
            throw new ExternalServiceException($this->service, $this->service . ' unreachable: ' . Redactor::redactString($e->getMessage()), 0, $e);
        }
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true);
        if ($status >= 400) {
            $detail = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? $decoded['reason'] ?? '') : '';
            if (is_array($decoded['message'] ?? null)) {
                $detail = (string) json_encode($decoded['message']);
            }
            $detail = $detail !== '' ? $detail : mb_strimwidth(strip_tags($body), 0, 200, '…');
            throw new ExternalServiceException($this->service, sprintf('%s API error HTTP %d: %s', $this->service, $status, Redactor::redactString($detail)), $status);
        }
        if (!is_array($decoded)) {
            throw new ExternalServiceException($this->service, $this->service . ' returned a non-JSON response.', $status);
        }
        return $decoded;
    }
}
