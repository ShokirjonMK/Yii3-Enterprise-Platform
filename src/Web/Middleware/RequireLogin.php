<?php

declare(strict_types=1);

namespace App\Web\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\User\CurrentUser;

final class RequireLogin implements MiddlewareInterface
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly UrlGeneratorInterface $urls,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->currentUser->isGuest()) {
            return $handler->handle($request);
        }
        $return = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        if ($query !== '') {
            $return .= '?' . $query;
        }
        $url = $this->urls->generate('auth.login', [], $request->getMethod() === 'GET' ? ['return' => $return] : []);
        return $this->responses->createResponse(303)->withHeader('Location', $url)->withHeader('HX-Redirect', $url);
    }

    /** Only same-origin relative paths are accepted as post-login redirect targets (open-redirect protection). */
    public static function safeReturn(?string $return): ?string
    {
        if ($return === null || $return === '' || !str_starts_with($return, '/') || str_starts_with($return, '//') || str_contains($return, '\\')
            || preg_match('/[\x00-\x1f]/', $return)) {
            return null;
        }
        return $return;
    }
}
