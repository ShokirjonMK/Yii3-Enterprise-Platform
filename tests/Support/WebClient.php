<?php

declare(strict_types=1);

namespace App\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal "browser" for E2E tests: cookie jar, CSRF extraction, form posts, HTMX headers,
 * redirect tracking. Talks to the running stack through nginx (E2E_BASE_URL).
 */
final class WebClient
{
    private Client $http;
    private CookieJar $jar;
    private string $lastBody = '';
    private ?ResponseInterface $last = null;
    private string $lastUrl = '';

    public function __construct(?string $baseUrl = null)
    {
        $this->jar = new CookieJar();
        $this->http = new Client([
            'base_uri' => $baseUrl ?? (getenv('E2E_BASE_URL') ?: 'http://nginx:8080'),
            'cookies' => $this->jar,
            'http_errors' => false,
            'allow_redirects' => ['max' => 10, 'track_redirects' => true],
            'timeout' => 30,
            'headers' => ['User-Agent' => 'yep-e2e/1.0', 'Accept-Language' => 'en'],
        ]);
    }

    public function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->record($path, $this->http->request('GET', $path, ['headers' => $headers]));
    }

    public function post(string $path, array $form = [], array $headers = [], bool $withCsrf = true): ResponseInterface
    {
        if ($withCsrf && !isset($form['_csrf'])) {
            $form['_csrf'] = $this->csrf();
        }
        return $this->record($path, $this->http->request('POST', $path, ['form_params' => $form, 'headers' => $headers]));
    }

    public function htmx(string $method, string $path, array $form = []): ResponseInterface
    {
        $headers = ['HX-Request' => 'true', 'HX-Current-URL' => $this->lastUrl, 'X-CSRF-Token' => $this->csrf()];
        $opts = ['headers' => $headers, 'allow_redirects' => false];
        if ($method !== 'GET') {
            $opts['form_params'] = $form;
        }
        return $this->record($path, $this->http->request($method, $path, $opts));
    }

    public function json(string $method, string $path, ?array $body = null, array $headers = []): ResponseInterface
    {
        $opts = ['headers' => $headers + ['Accept' => 'application/json']];
        if ($body !== null) {
            $opts['json'] = $body;
        }
        return $this->record($path, $this->http->request($method, $path, $opts));
    }

    /** Latest CSRF token rendered on the last HTML page (hidden input or meta tag). */
    public function csrf(): string
    {
        if (preg_match('/name="_csrf" value="([^"]+)"/', $this->lastBody, $m) || preg_match('/<meta name="csrf" content="([^"]+)"/', $this->lastBody, $m)) {
            return html_entity_decode($m[1]);
        }
        return '';
    }

    public function body(): string
    {
        return $this->lastBody;
    }

    public function last(): ?ResponseInterface
    {
        return $this->last;
    }

    /** Final URL after redirects. */
    public function url(): string
    {
        return $this->lastUrl;
    }

    public function cookies(): CookieJar
    {
        return $this->jar;
    }

    private function record(string $path, ResponseInterface $r): ResponseInterface
    {
        $this->last = $r;
        $this->lastBody = (string) $r->getBody();
        $history = $r->getHeader('X-Guzzle-Redirect-History');
        $this->lastUrl = $history !== [] ? (string) parse_url((string) end($history), PHP_URL_PATH) : (string) parse_url($path, PHP_URL_PATH);
        return $r;
    }
}
