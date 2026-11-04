<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\RequestProvider\RequestProviderInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\Flash\FlashInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Response helper for HTML actions (Admin + Web). Knows about HTMX:
 *  - redirect(): 303 for classic requests, HX-Location (AJAX navigation, history push) for HTMX requests
 *  - render(): full page; the layout itself emits only the main region for HTMX partial requests
 */
final class Responder
{
    public function __construct(
        private readonly WebViewRenderer $views,
        private readonly ResponseFactoryInterface $responses,
        private readonly UrlGeneratorInterface $urls,
        private readonly FlashInterface $flash,
        private readonly RequestProviderInterface $requestProvider,
    ) {}

    public function render(string $view, array $params = [], string $layout = 'admin', int $status = 200): ResponseInterface
    {
        return $this->views
            ->withLayout('@views/layouts/' . $layout . '.php')
            ->render($view, $params)
            ->withStatus($status);
    }

    /** Fragment without layout (HTMX targets such as table bodies, KPI tiles). */
    public function fragment(string $view, array $params = []): ResponseInterface
    {
        return $this->views->renderPartial($view, $params);
    }

    public function redirect(string $route, array $arguments = [], array $query = [], int $status = 303): ResponseInterface
    {
        return $this->redirectToUrl($this->urls->generate($route, $arguments, $query), $status);
    }

    public function redirectToUrl(string $url, int $status = 303): ResponseInterface
    {
        $request = $this->requestProvider->get();
        if (Htmx::isRequest($request)) {
            return $this->responses->createResponse(200)
                ->withHeader('HX-Location', (string) json_encode(['path' => $url, 'target' => '#main', 'select' => '#main']));
        }
        return $this->responses->createResponse($status)->withHeader('Location', $url);
    }

    public function success(string $message): void
    {
        $this->flash->add('success', $message);
    }

    public function error(string $message): void
    {
        $this->flash->add('error', $message);
    }

    public function info(string $message): void
    {
        $this->flash->add('info', $message);
    }

    public function json(mixed $data, int $status = 200): ResponseInterface
    {
        $r = $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
        $r->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $r;
    }

    public function download(string $content, string $filename, string $contentType): ResponseInterface
    {
        $r = $this->responses->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        $r->getBody()->write($content);
        return $r;
    }
}
