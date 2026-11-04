<?php

declare(strict_types=1);

namespace App\Api\V1\Http;

use App\Platform\Identity\Domain\Identity;
use App\Shared\Domain\Exception\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request helpers for API actions: authenticated identity, JSON body, collection parameters
 * (page, per_page ≤ 100, sort, filter[...], q) and optimistic-lock preconditions (If-Match / version).
 * Unknown query parameters are ignored.
 */
final class ApiRequest
{
    public const MAX_PER_PAGE = 100;

    public static function identity(ServerRequestInterface $request): Identity
    {
        $identity = $request->getAttribute('identity');
        if (!$identity instanceof Identity) {
            throw ApiException::unauthorized();
        }
        return $identity;
    }

    /** @return array<string,mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_object($body)) {
            $body = (array) $body;
        }
        if ($body === null || $body === '') {
            return [];
        }
        if (!is_array($body)) {
            throw new ApiException(400, 'invalid_body', 'The request body must be a JSON object.');
        }
        return $body;
    }

    public static function page(ServerRequestInterface $request): int
    {
        $v = $request->getQueryParams()['page'] ?? 1;
        $page = filter_var($v, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1) {
            throw ValidationException::single('page', 'page must be a positive integer.');
        }
        return min($page, 100000);
    }

    public static function perPage(ServerRequestInterface $request, int $default): int
    {
        $v = $request->getQueryParams()['per_page'] ?? null;
        if ($v === null || $v === '') {
            return max(1, min(self::MAX_PER_PAGE, $default));
        }
        $n = filter_var($v, FILTER_VALIDATE_INT);
        if ($n === false || $n < 1) {
            throw ValidationException::single('per_page', 'per_page must be a positive integer.');
        }
        if ($n > self::MAX_PER_PAGE) {
            throw ValidationException::single('per_page', sprintf('per_page must not exceed %d.', self::MAX_PER_PAGE));
        }
        return $n;
    }

    /**
     * @param array<string,string> $sortable whitelist (public key => SQL)
     */
    public static function sort(ServerRequestInterface $request, array $sortable): ?string
    {
        $sort = $request->getQueryParams()['sort'] ?? null;
        if ($sort === null || $sort === '') {
            return null;
        }
        if (!is_string($sort) || !isset($sortable[ltrim($sort, '-')])) {
            throw ValidationException::single('sort', 'Unsupported sort field. Allowed: ' . implode(', ', array_keys($sortable)) . ' (prefix "-" for descending).');
        }
        return $sort;
    }

    /**
     * filter[key]=value for whitelisted keys (+ free-text "q").
     *
     * @param list<string> $allowed
     * @return array<string,string>
     */
    public static function filters(ServerRequestInterface $request, array $allowed): array
    {
        $params = $request->getQueryParams();
        $raw = is_array($params['filter'] ?? null) ? $params['filter'] : [];
        $out = [];
        foreach ($allowed as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key]) && (string) $raw[$key] !== '') {
                $out[$key] = mb_substr((string) $raw[$key], 0, 200);
            }
        }
        if (isset($params['q']) && is_scalar($params['q']) && trim((string) $params['q']) !== '') {
            $out['q'] = mb_substr(trim((string) $params['q']), 0, 200);
        }
        return $out;
    }

    /**
     * Expected version for optimistic locking: If-Match header ("3", W/"3") or body "version".
     */
    public static function expectedVersion(ServerRequestInterface $request, array $body, bool $required): ?int
    {
        $header = trim($request->getHeaderLine('If-Match'));
        if ($header !== '' && preg_match('/^(?:W\/)?"?(\d{1,9})"?$/', $header, $m)) {
            return (int) $m[1];
        }
        if ($header !== '') {
            throw ValidationException::single('If-Match', 'If-Match must contain the resource ETag (version).');
        }
        if (isset($body['version'])) {
            $v = filter_var($body['version'], FILTER_VALIDATE_INT);
            if ($v === false || $v < 1) {
                throw ValidationException::single('version', 'version must be a positive integer.');
            }
            return $v;
        }
        if ($required) {
            throw new ApiException(428, 'precondition_required', 'Send If-Match: "<version>" (or "version" in the body) to update this resource.');
        }
        return null;
    }

    public static function etag(int $version): string
    {
        return '"' . $version . '"';
    }
}
