<?php

declare(strict_types=1);

namespace App\Api\V1\System;

use App\Api\V1\OpenApi\OpenApiGenerator;
use App\Environment;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Aliases\Aliases;

/**
 * OpenAPI document (/api/v1/openapi.json) and the Swagger UI page (/api/docs).
 * The UI is served from local vendored assets (public/static/swagger-ui, pinned 5.17.14) with a strict
 * page-specific CSP: only 'self' (no inline scripts; initializer in /static/api-docs.js).
 */
final class DocsController
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; "
        . "frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'";

    public function __construct(
        private readonly OpenApiGenerator $generator,
        private readonly ResponseFactoryInterface $responses,
        private readonly Aliases $aliases,
    ) {}

    #[OA\Get(
        path: '/api/v1/openapi.json',
        operationId: 'openapi',
        summary: 'This OpenAPI 3.1 document',
        security: [],
        tags: ['System'],
        responses: [new OA\Response(response: 200, description: 'OpenAPI document', content: new OA\JsonContent(type: 'object'))],
    )]
    public function openapi(): ResponseInterface
    {
        $json = $this->generator->document(fresh: !Environment::isProd());
        $r = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', Environment::isProd() ? 'public, max-age=300' : 'no-store');
        $r->getBody()->write($json);
        return $r;
    }

    public function page(): ResponseInterface
    {
        $specUrl = '/api/v1/openapi.json';
        ob_start();
        require $this->aliases->get('@api/V1/System/views/docs.php');
        $html = (string) ob_get_clean();
        $r = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', self::CSP);
        $r->getBody()->write($html);
        return $r;
    }
}
