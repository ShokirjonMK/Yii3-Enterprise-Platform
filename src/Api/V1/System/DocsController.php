<?php

declare(strict_types=1);

namespace App\Api\V1\System;

use App\Api\V1\OpenApi\OpenApiGenerator;
use App\Environment;
use App\Platform\Api\ApiDocsAccess;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Csrf\CsrfTokenInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/**
 * OpenAPI document (/api/v1/openapi.json) and the Swagger UI page (/api/docs). Both are private (ApiDocsAccess):
 * signed-in users with api.docs.view, or a browser session unlocked with a superadmin-issued access password.
 * Otherwise /api/docs shows the password form (with a sign-in link) and the JSON document answers 401.
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
        private readonly ApiDocsAccess $docsAccess,
        private readonly CurrentUser $currentUser,
        private readonly SessionInterface $session,
        private readonly Responder $responder,
        private readonly CsrfTokenInterface $csrfToken,
    ) {}

    #[OA\Get(
        path: '/api/v1/openapi.json',
        operationId: 'openapi',
        summary: 'This OpenAPI 3.1 document (signed-in session with api.docs.view, or a docs access password)',
        security: [],
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'OpenAPI document', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Documentation is private'),
        ],
    )]
    public function openapi(): ResponseInterface
    {
        if ($this->docsAccess->grant($this->currentUser, $this->session) === null) {
            return $this->responder->json(['error' => ['code' => 'docs_locked', 'message' => 'The API documentation is private. Open /api/docs to sign in or enter an access password.']], 401)
                ->withHeader('Cache-Control', 'no-store');
        }
        $json = $this->generator->document(fresh: !Environment::isProd());
        $r = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store');
        $r->getBody()->write($json);
        return $r;
    }

    public function page(): ResponseInterface
    {
        $via = $this->docsAccess->grant($this->currentUser, $this->session);
        if ($via === null) {
            return $this->locked();
        }
        $specUrl = '/api/v1/openapi.json';
        $lockable = $via === 'password';
        $csrf = $this->csrf();
        ob_start();
        require $this->aliases->get('@api/V1/System/views/docs.php');
        $html = (string) ob_get_clean();
        $r = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Security-Policy', self::CSP);
        $r->getBody()->write($html);
        return $r;
    }

    public function unlock(ServerRequestInterface $request): ResponseInterface
    {
        $password = (string) (((array) $request->getParsedBody())['password'] ?? '');
        try {
            $this->docsAccess->unlock($this->session, $password, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        } catch (ValidationException $e) {
            return $this->locked($e->errors(), 422);
        }
        return $this->responder->redirectToUrl('/api/docs');
    }

    public function lock(): ResponseInterface
    {
        $this->docsAccess->lock($this->session);
        return $this->responder->redirectToUrl('/api/docs');
    }

    private function locked(array $errors = [], int $status = 401): ResponseInterface
    {
        return $this->responder->render('@api/V1/System/views/docs-locked', ['errors' => $errors], 'auth', $status)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function csrf(): string
    {
        return $this->csrfToken->getValue();
    }
}
