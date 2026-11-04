<?php

declare(strict_types=1);

namespace App\Api\V1\Http;

use App\Shared\Infrastructure\Context\RequestContext;
use App\Shared\Infrastructure\Database\Page;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * JSON responses with the standard API v1 envelope:
 *   success:    {"data": …, "meta": {…}, "links": {…}}
 *   error:      {"error": {"code", "message", "details", "correlation_id"}}
 */
final class ApiResponder
{
    public const JSON = 'application/json; charset=utf-8';
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly RequestContext $context,
    ) {}

    /**
     * @param array<string,string> $headers
     */
    public function data(mixed $data, int $status = 200, array $headers = [], ?array $meta = null, ?array $links = null): ResponseInterface
    {
        $body = ['data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }
        if ($links !== null) {
            $body['links'] = $links;
        }
        return $this->raw($body, $status, $headers);
    }

    /**
     * Paginated collection; links preserve the query string (filters, sort) of the request.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $map
     */
    public function collection(Page $page, ServerRequestInterface $request, callable $map): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $query = $request->getQueryParams();
        $link = static function (int $p) use ($path, $query, $page): string {
            $q = array_merge($query, ['page' => $p, 'per_page' => $page->perPage]);
            return $path . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        };
        $pages = $page->pages();
        $links = [
            'self' => $link($page->page),
            'first' => $link(1),
            'last' => $link($pages),
            'prev' => $page->page > 1 ? $link(min($page->page - 1, $pages)) : null,
            'next' => $page->page < $pages ? $link($page->page + 1) : null,
        ];
        return $this->data(array_map($map, $page->items), 200, ['X-Total-Count' => (string) $page->total], ['pagination' => $page->meta()], $links);
    }

    public function noContent(): ResponseInterface
    {
        return $this->responses->createResponse(204);
    }

    /** @param array<string, list<string>|string> $details */
    public function error(int $status, string $code, string $message, array $details = [], array $headers = []): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        $error['correlation_id'] = $this->context->correlationId();
        return $this->raw(['error' => $error], $status, $headers);
    }

    /** @param array<string,string> $headers */
    public function raw(array $body, int $status = 200, array $headers = []): ResponseInterface
    {
        $r = $this->responses->createResponse($status)->withHeader('Content-Type', self::JSON);
        foreach ($headers as $k => $v) {
            $r = $r->withHeader($k, $v);
        }
        $r->getBody()->write((string) json_encode($body, self::FLAGS));
        return $r;
    }
}
