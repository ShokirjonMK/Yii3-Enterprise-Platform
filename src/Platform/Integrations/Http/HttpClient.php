<?php

declare(strict_types=1);

namespace App\Platform\Integrations\Http;

use App\Platform\Integrations\Exception;
use App\Platform\Integrations\Exception\IntegrationException;
use App\Platform\Integrations\Webhook\WebhookSigner;
use App\Platform\Monitoring\Metrics;
use App\Platform\Security\UrlGuard;
use App\Shared\Infrastructure\Context\RequestContext;
use App\Shared\Infrastructure\Logging\Redactor;
use App\Shared\Infrastructure\RateLimit\RedisRateLimiter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Platform-wide outbound HTTP client (Guzzle 7 HandlerStack). Every call to an external system goes through it.
 *
 * Middleware order (outer → inner):
 *   retry          exponential backoff + full jitter; only idempotent methods (GET/HEAD/OPTIONS/PUT/DELETE)
 *                  unless the caller passes ['retry' => true]; Retry-After honoured (≤ 30 s)
 *   rate limit     RedisRateLimiter, integrations.rate_limit_per_minute (0 = unlimited)
 *   circuit        {@see CircuitBreaker} (threshold & cooldown from the integration row)
 *   headers/auth   X-Request-Id + W3C traceparent from RequestContext; bearer/basic/api_key/hmac auth
 *   log + metrics  one integration_logs row per attempt (redacted headers, ≤ 2 KB redacted body samples),
 *                  integration_requests_total / integration_request_duration_seconds
 *
 * Before anything is sent the destination is checked by {@see UrlGuard} (SSRF) and the connection is pinned to
 * the vetted IP (CURLOPT_RESOLVE); redirects are never followed. Non-2xx/3xx responses and transport errors
 * become typed exceptions ({@see Exception\IntegrationException} subclasses).
 *
 * Extra request options (removed before Guzzle sees them):
 *   'retry'   => bool         allow retries for non-idempotent methods (POST/PATCH) — only for idempotent APIs
 *   'secrets' => list<string> additional values to mask in logs (e.g. a token that is part of the URL path)
 */
final class HttpClient
{
    public const SAMPLE_BYTES = 2048;
    private const IDEMPOTENT = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE', 'TRACE'];
    private const RETRY_STATUSES = [408, 429, 500, 502, 503, 504];
    private const MAX_DELAY_MS = 30000;
    private const SENSITIVE_HEADER = '/auth|key|token|secret|signature|cookie|session|password/i';

    /** @var callable|null */
    private $handler;

    /**
     * @param callable|null $handler Guzzle transport handler (tests pass a MockHandler); default: cURL
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CircuitBreaker $breaker,
        private readonly RedisRateLimiter $limiter,
        private readonly RequestContext $context,
        private readonly Metrics $metrics,
        private readonly UrlGuard $guard,
        private readonly ?LoggerInterface $logger = null,
        ?callable $handler = null,
    ) {
        $this->handler = $handler;
    }

    /** Same client with another transport (tests, special TLS settings). */
    public function withHandler(callable $handler): self
    {
        return new self($this->db, $this->breaker, $this->limiter, $this->context, $this->metrics, $this->guard, $this->logger, $handler);
    }

    public function withGuard(UrlGuard $guard): self
    {
        return new self($this->db, $this->breaker, $this->limiter, $this->context, $this->metrics, $guard, $this->logger, $this->handler);
    }

    public function breaker(): CircuitBreaker
    {
        return $this->breaker;
    }

    /**
     * @param array<string,mixed> $options Guzzle request options (headers, query, json, body, form_params) + 'retry', 'secrets'
     * @throws IntegrationException
     */
    public function send(IntegrationConfig $cfg, string $method, string $uri, array $options = []): ResponseInterface
    {
        $method = strtoupper($method);
        $secrets = array_values(array_filter([$cfg->secret, ...((array) ($options['secrets'] ?? []))], static fn($s) => is_string($s) && $s !== ''));
        $allowRetry = (bool) ($options['retry'] ?? false);
        unset($options['retry'], $options['secrets']);

        $url = self::buildUrl($cfg->baseUrl, $uri);
        $t0 = hrtime(true);
        try {
            if (!$cfg->enabled) {
                throw new Exception\NotConfiguredException("Integration \"{$cfg->code}\" is disabled.", 'not_configured', null, $cfg->code);
            }
            if ($url === null) {
                throw new Exception\NotConfiguredException("Integration \"{$cfg->code}\" has no base URL.", 'not_configured', null, $cfg->code);
            }
            if ($cfg->authType !== 'none' && ($cfg->secret === null || $cfg->secret === '')) {
                throw new Exception\NotConfiguredException("Integration \"{$cfg->code}\" requires a secret for auth type {$cfg->authType}.", 'not_configured', null, $cfg->code);
            }
            $target = $this->guard->assertAllowed($url, $cfg->allowPrivateNetwork);
        } catch (IntegrationException $e) {
            $this->logAttempt($cfg, $method, $url ?? $uri, [], null, null, null, $e, $t0, 1, $secrets);
            throw $e;
        }

        $stack = HandlerStack::create($this->handler ?? Utils::chooseHandler());
        $stack->push(Middleware::retry($this->retryDecider($cfg, $method, $allowRetry), $this->retryDelay($cfg)), 'retry');
        $stack->push($this->rateLimitMiddleware($cfg), 'rate_limit');
        $stack->push($this->circuitMiddleware($cfg), 'circuit');
        $stack->push($this->headersMiddleware($cfg), 'headers');
        $stack->push($this->logMiddleware($cfg, $secrets), 'log');

        $ipForCurl = str_contains($target['ips'][0], ':') ? '[' . $target['ips'][0] . ']' : $target['ips'][0];
        $headers = ['User-Agent' => 'Yii3EnterprisePlatform/1.0', 'Accept' => 'application/json, */*;q=0.5'] + $cfg->headers;
        $guzzleOptions = array_replace($options, [
            'headers' => array_replace($headers, (array) ($options['headers'] ?? [])),
            'timeout' => $cfg->timeoutMs / 1000,
            'connect_timeout' => $cfg->connectTimeoutMs / 1000,
            'http_errors' => false,
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $ipForCurl]],
        ]);

        try {
            $response = (new Client(['handler' => $stack]))->request($method, $url, $guzzleOptions);
        } catch (Exception\CircuitOpenException|Exception\RateLimitedException $e) {
            // rejected locally before the network: log once (the log middleware did not see it)
            $this->logAttempt($cfg, $method, $url, [], null, null, null, $e, $t0, 1, $secrets);
            throw $e;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $err = self::classifyTransport($e, $cfg->code, $secrets);
            $this->trackStatus($cfg, $err);
            throw $err;
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $err = self::classifyResponse($response, $cfg->code, $this->sample($this->readBody($response), $response->getHeaderLine('Content-Type'), $secrets));
            $this->trackStatus($cfg, $err);
            throw $err;
        }
        $this->trackStatus($cfg, null);
        return $response;
    }

    /**
     * Convenience: decoded JSON body (empty array for an empty body).
     *
     * @return array<mixed>
     */
    public function sendJson(IntegrationConfig $cfg, string $method, string $uri, array $options = []): array
    {
        $body = (string) $this->send($cfg, $method, $uri, $options)->getBody();
        if (trim($body) === '') {
            return [];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception\ClientErrorException("Integration \"{$cfg->code}\" returned a non-JSON response.", 'invalid_response', null, $cfg->code);
        }
        return $data;
    }

    // ------------------------------------------------------------------------------------------ classification

    public static function classifyResponse(ResponseInterface $r, string $code, ?string $sample = null): IntegrationException
    {
        $status = $r->getStatusCode();
        $msg = "Integration \"$code\" responded HTTP $status";
        return match (true) {
            $status === 401 => new Exception\UnauthorizedException("$msg (unauthorized).", 'unauthorized', $status, $code, null, $sample),
            $status === 403 => new Exception\ForbiddenException("$msg (forbidden).", 'forbidden', $status, $code, null, $sample),
            $status === 404 => new Exception\NotFoundException("$msg (not found).", 'not_found', $status, $code, null, $sample),
            $status === 408 => new Exception\RequestTimeoutException("$msg (request timeout).", 'timeout', $status, $code, null, $sample),
            $status === 429 => new Exception\RateLimitedException("$msg (rate limited).", self::retryAfterSeconds($r) ?? 60, 'rate_limited', 429, $code, $sample),
            $status === 502 => new Exception\BadGatewayException("$msg (bad gateway).", 'bad_gateway', $status, $code, null, $sample),
            $status === 503 => new Exception\ServiceUnavailableException("$msg (service unavailable).", 'unavailable', $status, $code, null, $sample),
            $status === 504 => new Exception\GatewayTimeoutException("$msg (gateway timeout).", 'gateway_timeout', $status, $code, null, $sample),
            $status >= 500 => new Exception\ServerErrorException("$msg (server error).", 'server_error', $status, $code, null, $sample),
            default => new Exception\ClientErrorException("$msg.", 'client_error', $status, $code, null, $sample),
        };
    }

    /** @param list<string> $secrets */
    public static function classifyTransport(Throwable $e, string $code, array $secrets = []): IntegrationException
    {
        if ($e instanceof IntegrationException) {
            return $e;
        }
        $errno = 0;
        if ($e instanceof RequestException || $e instanceof ConnectException) {
            $errno = (int) ($e->getHandlerContext()['errno'] ?? 0);
        }
        $raw = self::mask($e->getMessage(), $secrets);
        $m = strtolower($raw);
        $tlsErrnos = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];
        $kind = match (true) {
            $errno === 28 || str_contains($m, 'timed out') || str_contains($m, 'timeout') => 'timeout',
            $errno === 6 || $errno === 5 || str_contains($m, 'could not resolve') || str_contains($m, 'name or service not known') => 'dns',
            in_array($errno, $tlsErrnos, true) || str_contains($m, 'ssl') || str_contains($m, 'certificate') || str_contains($m, 'tls') => 'tls',
            $errno === 7 || str_contains($m, 'connection refused') || str_contains($m, 'failed to connect') => 'connect',
            default => 'network',
        };
        $msg = "Integration \"$code\": " . match ($kind) {
            'timeout' => 'request timed out',
            'dns' => 'DNS resolution failed',
            'tls' => 'TLS handshake/certificate error',
            'connect' => 'connection refused/unreachable',
            default => 'network error',
        } . ' — ' . Redactor::redactString(mb_substr($raw, 0, 300));
        return $kind === 'timeout'
            ? new Exception\RequestTimeoutException($msg, 'timeout', null, $code, $e)
            : new Exception\NetworkException($msg, $kind, null, $code, $e);
    }

    public static function retryAfterSeconds(ResponseInterface $r): ?int
    {
        $h = trim($r->getHeaderLine('Retry-After'));
        if ($h === '') {
            return null;
        }
        if (ctype_digit($h)) {
            return (int) $h;
        }
        $ts = strtotime($h);
        return $ts === false ? null : max(0, $ts - time());
    }

    public static function buildUrl(string $base, string $uri): ?string
    {
        if (preg_match('#^https?://#i', $uri) === 1) {
            return $uri;
        }
        if ($base === '') {
            return null;
        }
        return $uri === '' ? $base : rtrim($base, '/') . '/' . ltrim($uri, '/');
    }

    public static function isIdempotent(string $method): bool
    {
        return in_array(strtoupper($method), self::IDEMPOTENT, true);
    }

    // ------------------------------------------------------------------------------------------ middleware

    private function retryDecider(IntegrationConfig $cfg, string $method, bool $allowRetry): callable
    {
        return static function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, mixed $reason = null) use ($cfg, $method, $allowRetry): bool {
            if ($retries >= $cfg->maxRetries || (!self::isIdempotent($method) && !$allowRetry)) {
                return false;
            }
            if ($response !== null) {
                if (!in_array($response->getStatusCode(), self::RETRY_STATUSES, true)) {
                    return false;
                }
                $after = self::retryAfterSeconds($response);
                return $after === null || $after * 1000 <= self::MAX_DELAY_MS;
            }
            if ($reason instanceof IntegrationException) {
                return false; // circuit open, local rate limit
            }
            if ($reason instanceof TransferException) {
                return self::classifyTransport($reason, $cfg->code)->isRetryable();
            }
            return false;
        };
    }

    private function retryDelay(IntegrationConfig $cfg): callable
    {
        return static function (int $retries, ?ResponseInterface $response = null) use ($cfg): int {
            if ($response !== null && ($after = self::retryAfterSeconds($response)) !== null) {
                return min(self::MAX_DELAY_MS, $after * 1000);
            }
            $base = $cfg->backoffMs * (2 ** max(0, $retries - 1));
            $jitter = $cfg->backoffMs > 0 ? random_int(0, $cfg->backoffMs) : 0;
            return (int) min(self::MAX_DELAY_MS, $base + $jitter);
        };
    }

    private function rateLimitMiddleware(IntegrationConfig $cfg): callable
    {
        return fn(callable $next): callable => function (RequestInterface $request, array $options) use ($next, $cfg): PromiseInterface {
            if ($cfg->rateLimitPerMinute > 0) {
                $r = $this->limiter->hit('integration:' . $cfg->code, $cfg->rateLimitPerMinute, 60);
                if (!$r->allowed) {
                    return Create::rejectionFor(new Exception\RateLimitedException(
                        "Local rate limit for \"{$cfg->code}\" exceeded ({$cfg->rateLimitPerMinute}/min).",
                        max(1, $r->resetInSeconds),
                        'rate_limited_local',
                        null,
                        $cfg->code,
                    ));
                }
            }
            return $next($request, $options);
        };
    }

    private function circuitMiddleware(IntegrationConfig $cfg): callable
    {
        return fn(callable $next): callable => function (RequestInterface $request, array $options) use ($next, $cfg): PromiseInterface {
            try {
                $this->breaker->acquire($cfg->code, $cfg->circuitThreshold, $cfg->circuitCooldownS);
            } catch (Exception\CircuitOpenException $e) {
                return Create::rejectionFor($e);
            }
            return $next($request, $options)->then(
                function (ResponseInterface $response) use ($cfg): ResponseInterface {
                    $s = $response->getStatusCode();
                    if ($s >= 500 || $s === 408 || $s === 429) {
                        $this->breaker->recordFailure($cfg->code, $cfg->circuitThreshold, $cfg->circuitCooldownS);
                    } else {
                        $this->breaker->recordSuccess($cfg->code);
                    }
                    return $response;
                },
                function (mixed $reason) use ($cfg): PromiseInterface {
                    if ($reason instanceof TransferException) {
                        $this->breaker->recordFailure($cfg->code, $cfg->circuitThreshold, $cfg->circuitCooldownS);
                    }
                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function headersMiddleware(IntegrationConfig $cfg): callable
    {
        return fn(callable $next): callable => function (RequestInterface $request, array $options) use ($next, $cfg): PromiseInterface {
            $cid = $this->context->correlationId();
            $traceId = preg_match('/^[0-9a-f]{32}$/', $cid) === 1 && $cid !== str_repeat('0', 32) ? $cid : md5($cid);
            $request = $request
                ->withHeader('X-Request-Id', $cid)
                ->withHeader('traceparent', '00-' . $traceId . '-' . bin2hex(random_bytes(8)) . '-01');
            $secret = (string) $cfg->secret;
            switch ($cfg->authType) {
                case 'bearer':
                    $request = $request->withHeader('Authorization', 'Bearer ' . $secret);
                    break;
                case 'basic':
                    $request = $request->withHeader('Authorization', 'Basic ' . base64_encode((string) ($cfg->authConfig['username'] ?? '') . ':' . $secret));
                    break;
                case 'api_key':
                    $request = $request->withHeader((string) ($cfg->authConfig['api_key_header'] ?? '') ?: 'X-API-Key', $secret);
                    break;
                case 'hmac':
                    $body = (string) $request->getBody();
                    $request->getBody()->isSeekable() && $request->getBody()->rewind();
                    $signed = WebhookSigner::headers($secret, $body, null, (string) ($cfg->authConfig['hmac_header'] ?? '') ?: 'X-Signature', (string) ($cfg->authConfig['hmac_timestamp_header'] ?? '') ?: 'X-Timestamp');
                    foreach ($signed as $name => $value) {
                        $request = $request->withHeader($name, $value);
                    }
                    break;
            }
            return $next($request, $options);
        };
    }

    /** @param list<string> $secrets */
    private function logMiddleware(IntegrationConfig $cfg, array $secrets): callable
    {
        return fn(callable $next): callable => function (RequestInterface $request, array $options) use ($next, $cfg, $secrets): PromiseInterface {
            $t0 = hrtime(true);
            $attempt = (int) ($options['retries'] ?? 0) + 1;
            $reqBody = (string) $request->getBody();
            $request->getBody()->isSeekable() && $request->getBody()->rewind();
            $reqHeaders = [];
            foreach ($request->getHeaders() as $name => $values) {
                $reqHeaders[$name] = implode(', ', $values);
            }
            $reqSample = $this->sample($reqBody, $request->getHeaderLine('Content-Type'), $secrets);
            $url = (string) $request->getUri();
            return $next($request, $options)->then(
                function (ResponseInterface $response) use ($cfg, $request, $url, $reqHeaders, $reqSample, $t0, $attempt, $secrets): ResponseInterface {
                    $sample = $this->sample($this->readBody($response), $response->getHeaderLine('Content-Type'), $secrets);
                    $err = $response->getStatusCode() >= 400 ? self::classifyResponse($response, $cfg->code) : null;
                    $this->logAttempt($cfg, $request->getMethod(), $url, $reqHeaders, $reqSample, $response->getStatusCode(), $sample, $err, $t0, $attempt, $secrets);
                    return $response;
                },
                function (mixed $reason) use ($cfg, $request, $url, $reqHeaders, $reqSample, $t0, $attempt, $secrets): PromiseInterface {
                    $err = $reason instanceof Throwable ? self::classifyTransport($reason, $cfg->code, $secrets) : null;
                    $this->logAttempt($cfg, $request->getMethod(), $url, $reqHeaders, $reqSample, null, null, $err, $t0, $attempt, $secrets);
                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    // ------------------------------------------------------------------------------------------ logging

    /**
     * @param array<string,string> $headers
     * @param list<string> $secrets
     */
    private function logAttempt(IntegrationConfig $cfg, string $method, string $url, array $headers, ?string $reqSample, ?int $status, ?string $resSample, ?IntegrationException $err, int $t0, int $attempt, array $secrets): void
    {
        $durationMs = (int) round((hrtime(true) - $t0) / 1e6);
        $outcome = $err?->errorClass() ?? 'ok';
        $this->metrics->inc('integration_requests_total', 'Outbound integration HTTP requests', [
            'integration' => $cfg->code, 'method' => $method, 'status' => $status === null ? 'none' : (string) $status, 'outcome' => $outcome,
        ]);
        if ($status !== null || $err === null || $err instanceof Exception\NetworkException || $err instanceof Exception\RequestTimeoutException) {
            $this->metrics->observe('integration_request_duration_seconds', 'Outbound integration request duration', $durationMs / 1000, ['integration' => $cfg->code]);
        }
        $masked = [];
        foreach ($headers as $name => $value) {
            $masked[$name] = preg_match(self::SENSITIVE_HEADER, $name) === 1 ? Redactor::MASK : self::mask(Redactor::redactString($value), $secrets);
        }
        try {
            $this->db->createCommand()->insert('integration_logs', [
                'integration_id' => $cfg->id,
                'correlation_id' => $this->context->correlationId(),
                'method' => substr($method, 0, 8),
                'url' => mb_substr(self::mask(Redactor::redactString($url), $secrets), 0, 2048),
                'request_headers' => json_encode($masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'request_sample' => $reqSample,
                'status_code' => $status,
                'response_sample' => $resSample,
                'duration_ms' => $durationMs,
                'attempt' => $attempt,
                'error_class' => $err === null ? null : substr($err->errorClass(), 0, 32),
                'error_message' => $err === null ? null : mb_substr(self::mask($err->getMessage(), $secrets), 0, 2000),
            ])->execute();
        } catch (Throwable $e) {
            $this->logger?->warning('integration_logs write failed', ['exception' => $e->getMessage(), 'integration' => $cfg->code]);
        }
    }

    /** Redacted, size-bounded sample of a body (JSON is redacted structurally). @param list<string> $secrets */
    private function sample(string $body, string $contentType, array $secrets): ?string
    {
        if ($body === '') {
            return null;
        }
        $head = substr($body, 0, 65536);
        $decoded = str_contains(strtolower($contentType), 'json') || in_array($head[0] ?? '', ['{', '['], true) ? json_decode($head, true) : null;
        if (is_array($decoded)) {
            $out = (string) json_encode(Redactor::redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } elseif (str_contains(strtolower($contentType), 'x-www-form-urlencoded')) {
            parse_str($head, $form);
            $out = http_build_query(Redactor::redact($form));
        } else {
            $out = Redactor::redactString($head);
        }
        $out = self::mask($out, $secrets);
        if (!mb_check_encoding($out, 'UTF-8')) {
            $out = '[binary ' . strlen($body) . ' bytes]';
        }
        return strlen($out) > self::SAMPLE_BYTES ? mb_strcut($out, 0, self::SAMPLE_BYTES - 3, 'UTF-8') . '...' : $out;
    }

    private function readBody(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $content = $stream->read(65536);
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            return $content;
        } catch (Throwable) {
            return '';
        }
    }

    /** @param list<string> $secrets */
    private static function mask(string $text, array $secrets): string
    {
        foreach ($secrets as $s) {
            if (strlen($s) >= 4) {
                $text = str_replace([$s, rawurlencode($s)], Redactor::MASK, $text);
            }
        }
        return $text;
    }

    /** Integration status tracking: healthy after a success, degraded after a server/transport failure. */
    private function trackStatus(IntegrationConfig $cfg, ?IntegrationException $err): void
    {
        if ($cfg->id === null) {
            return;
        }
        try {
            if ($err === null) {
                if ($cfg->status !== 'healthy' && $cfg->status !== 'maintenance') {
                    $this->db->createCommand()->update('integrations', ['status' => 'healthy', 'last_error' => null], ['id' => $cfg->id])->execute();
                }
                return;
            }
            if ($err->isRetryable() || $err instanceof Exception\NetworkException || $err instanceof Exception\UnauthorizedException) {
                $this->db->createCommand()->update('integrations', ['status' => 'degraded', 'last_error' => mb_substr($err->getMessage(), 0, 1000)], ['id' => $cfg->id])->execute();
            }
        } catch (Throwable) {
        }
    }
}
