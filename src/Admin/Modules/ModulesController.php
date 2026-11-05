<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Platform\Modules\ModuleRegistry;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * 23 · Business Modules — registry (manifests + business_modules), enable/disable through the module flag.
 */
final class ModulesController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ModuleRegistry $modules,
        private readonly CurrentUser $user,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->responder->render('@admin/Modules/views/index', ['modules' => $this->modules->all()]);
    }

    public function show(CurrentRoute $route): ResponseInterface
    {
        return $this->responder->render('@admin/Modules/views/show', ['m' => $this->modules->get((string) $route->getArgument('code'))]);
    }

    public function toggle(CurrentRoute $route, ServerRequestInterface $request): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        $enable = (((array) $request->getParsedBody())['enabled'] ?? '0') === '1';
        try {
            $this->modules->setEnabled((string) $this->user->getId(), $code, $enable);
            $this->responder->success($enable ? 'Module enabled.' : 'Module disabled.');
        } catch (ValidationException | ConflictException | AccessDeniedException | NotFoundException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.modules.show', ['code' => $code]);
    }

    public function sync(): ResponseInterface
    {
        $n = $this->modules->sync((string) $this->user->getId());
        $this->responder->success(sprintf('Registry synchronised (%d change(s)).', $n));
        return $this->responder->redirect('admin.modules');
    }
}
