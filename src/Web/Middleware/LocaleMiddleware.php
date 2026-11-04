<?php

declare(strict_types=1);

namespace App\Web\Middleware;

use App\Platform\Identity\Domain\Identity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;

/**
 * Locale resolution: session choice → user profile → Accept-Language → default.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    /** @param list<string> $supported */
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly SessionInterface $session,
        private readonly CurrentUser $currentUser,
        private readonly array $supported = ['uz', 'ru', 'en'],
        private readonly string $default = 'uz',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->session->get('locale');
        if (!is_string($locale) || !in_array($locale, $this->supported, true)) {
            $identity = $this->currentUser->getIdentity();
            $locale = $identity instanceof Identity ? $identity->user->locale : null;
        }
        if (!is_string($locale) || !in_array($locale, $this->supported, true)) {
            $locale = $this->fromHeader($request->getHeaderLine('Accept-Language')) ?? $this->default;
        }
        $this->translator->setLocale($locale);
        return $handler->handle($request->withAttribute('locale', $locale))->withHeader('Content-Language', $locale);
    }

    private function fromHeader(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            $lang = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            if (in_array($lang, $this->supported, true)) {
                return $lang;
            }
        }
        return null;
    }
}
