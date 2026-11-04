<?php

declare(strict_types=1);

namespace App\Api\V1\Middleware;

use App\Api\V1\Http\ApiResponder;
use App\Api\V1\Http\CallbackHandler;
use App\Platform\Identity\Domain\Identity;
use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Auth\IdentityWithTokenRepositoryInterface;
use Yiisoft\Auth\Method\HttpBearer;
use Yiisoft\Auth\Middleware\Authentication;

/**
 * Bearer authentication for protected API routes, built on yiisoft/auth (Authentication + HttpBearer)
 * with the platform IdentityWithTokenRepositoryInterface (opaque access/personal tokens).
 * Failure: 401 JSON envelope + `WWW-Authenticate: Bearer realm="api"`.
 * On success the Identity is exposed as request attribute "identity" and published to RequestContext.
 */
final class BearerAuthentication implements MiddlewareInterface
{
    public function __construct(
        private readonly IdentityWithTokenRepositoryInterface $identities,
        private readonly ResponseFactoryInterface $responses,
        private readonly RequestContext $context,
        private readonly ApiResponder $responder,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $failure = new CallbackHandler(fn(): ResponseInterface => $this->responder->error(
            401,
            'unauthorized',
            'A valid Bearer access token is required.',
        ));
        $authentication = new Authentication((new HttpBearer($this->identities))->withRealm('api'), $this->responses, $failure);

        return $authentication->process($request, new CallbackHandler(function (ServerRequestInterface $request) use ($handler): ResponseInterface {
            $identity = $request->getAttribute(Authentication::class);
            if ($identity instanceof Identity) {
                $this->context->setUserId($identity->user->id);
                $this->context->setTenantId($identity->user->tenantId);
                $request = $request->withAttribute('identity', $identity);
            }
            return $handler->handle($request);
        }));
    }
}
