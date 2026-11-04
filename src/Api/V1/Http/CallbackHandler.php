<?php

declare(strict_types=1);

namespace App\Api\V1\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** PSR-15 handler backed by a closure (used to compose middleware). */
final class CallbackHandler implements RequestHandlerInterface
{
    /** @param Closure(ServerRequestInterface):ResponseInterface $fn */
    public function __construct(private readonly Closure $fn) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->fn)($request);
    }
}
