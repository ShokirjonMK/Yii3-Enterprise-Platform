<?php

declare(strict_types=1);

namespace App\Web\Action\System;

use App\Platform\Appearance\ThemeRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * Serves a theme stylesheet. With the current ?v=<hash> it is cached for a year (immutable, the hash changes
 * with the content); without it, for 5 minutes. Works the same on every instance (uploaded CSS is in the DB).
 *
 * Author: ShokirjonMK
 */
final class ThemeCssAction
{
    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function css(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        $theme = $this->registry->get($code);
        $css = $theme === null ? null : $this->registry->css($code);
        if ($css === null) {
            return $this->responses->createResponse(404)->withHeader('Content-Type', 'text/css; charset=utf-8')->withHeader('Cache-Control', 'no-store');
        }
        $etag = '"' . $theme['hash'] . '"';
        $cache = ($request->getQueryParams()['v'] ?? '') === $theme['hash'] ? 'public, max-age=31536000, immutable' : 'public, max-age=300';
        if (trim($request->getHeaderLine('If-None-Match')) === $etag) {
            return $this->responses->createResponse(304)->withHeader('ETag', $etag)->withHeader('Cache-Control', $cache);
        }
        $response = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', $cache)
            ->withHeader('ETag', $etag);
        $response->getBody()->write($css);
        return $response;
    }
}
