<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Exception\TooManyRequestsException;
use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Maps domain exceptions to HTTP responses.
 *  - mode "json": standard API error envelope
 *  - mode "html": themed error page (admin/web)
 * Unexpected exceptions in "json" mode become a generic 500 without internals; in "html" mode they
 * bubble up to yiisoft/error-handler (which hides details when APP_DEBUG=false).
 */
final class DomainErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly RequestContext $context,
        private readonly LoggerInterface $logger,
        private readonly WebViewRenderer $views,
        private readonly string $mode = 'html',
    ) {}

    public static function json(): array
    {
        return ['class' => self::class, '__construct()' => ['mode' => 'json']];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (DomainException $e) {
            return $this->mode === 'json' ? $this->jsonError($e->httpStatus(), $e->errorCode(), $e->getMessage(), $e->details(), $e) : $this->htmlError($e);
        } catch (\Yiisoft\Http\Exception\MethodNotAllowedException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($this->mode !== 'json') {
                throw $e;
            }
            $this->logger->error('Unhandled API exception', ['exception' => $e]);
            return $this->jsonError(500, 'internal_error', 'An internal error occurred. Reference: ' . $this->context->correlationId(), [], null);
        }
    }

    public function jsonError(int $status, string $code, string $message, array $details, ?Throwable $e): ResponseInterface
    {
        $body = ['error' => array_filter([
            'code' => $code,
            'message' => $message,
            'details' => $details ?: null,
            'correlation_id' => $this->context->correlationId(),
        ], static fn($v) => $v !== null)];
        $response = $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
        if ($e instanceof TooManyRequestsException) {
            $response = $response->withHeader('Retry-After', (string) $e->retryAfter());
        }
        $response->getBody()->write((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }

    private function htmlError(DomainException $e): ResponseInterface
    {
        $response = $this->views->render('errors/error', [
            'status' => $e->httpStatus(),
            'code' => $e->errorCode(),
            'message' => $e->getMessage(),
            'correlationId' => $this->context->correlationId(),
        ])->withStatus($e->httpStatus());
        if ($e instanceof TooManyRequestsException) {
            $response = $response->withHeader('Retry-After', (string) $e->retryAfter());
        }
        return $response;
    }
}
