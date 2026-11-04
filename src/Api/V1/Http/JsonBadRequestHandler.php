<?php

declare(strict_types=1);

namespace App\Api\V1\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Request\Body\BadRequestHandlerInterface;
use Yiisoft\Request\Body\ParserException;

/** Malformed JSON request bodies → 400 in the standard error envelope. */
final class JsonBadRequestHandler implements BadRequestHandlerInterface
{
    private ?ParserException $exception = null;

    public function __construct(private readonly ApiResponder $responder) {}

    public function withParserException(ParserException $e): self
    {
        $new = clone $this;
        $new->exception = $e;
        return $new;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responder->error(400, 'invalid_json', 'The request body is not valid JSON.');
    }
}
