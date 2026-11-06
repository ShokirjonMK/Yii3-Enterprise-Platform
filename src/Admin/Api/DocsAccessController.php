<?php

declare(strict_types=1);

namespace App\Admin\Api;

use App\Admin\Users\AdminActor;
use App\Platform\Api\ApiDocsAccess;
use App\Platform\Api\ApiClientService;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * API Management → Docs access: who can open /api/docs. Users get it through the api.docs.view permission
 * (RBAC); people without an account get an access password from a superadmin (api.docs.manage), shown once.
 */
final class DocsAccessController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AdminActor $actor,
        private readonly ApiDocsAccess $docs,
        private readonly ApiClientService $clients,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->page(['label' => '', 'days' => 30], []);
    }

    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        $in = AdminActor::body($request);
        try {
            $issued = $this->docs->issue($this->actor->user()->id, (string) ($in['label'] ?? ''), (int) ($in['days'] ?? 0));
        } catch (ValidationException $e) {
            return $this->page($in, $e->errors(), 422);
        }
        return $this->page(['label' => '', 'days' => 30], [], 200, $issued + ['label' => (string) $in['label']]);
    }

    public function revoke(CurrentRoute $route): ResponseInterface
    {
        $this->docs->revoke($this->actor->user()->id, (string) $route->getArgument('id'));
        $this->responder->success('Password revoked. Browsers unlocked with it lose access on their next request.');
        return $this->responder->redirect('admin.api.docs');
    }

    private function page(array $values, array $errors, int $status = 200, ?array $issued = null): ResponseInterface
    {
        return $this->responder->render('@admin/Api/views/docs-access', [
            'passwords' => $this->docs->list(),
            'usage' => $this->clients->usage($this->actor->user()),
            'values' => $values,
            'errors' => $errors,
            'issued' => $issued,
            'maxDays' => ApiDocsAccess::MAX_DAYS,
        ], status: $status);
    }
}
