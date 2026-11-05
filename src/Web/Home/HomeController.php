<?php

declare(strict_types=1);

namespace App\Web\Home;

use App\Platform\Access\AccessService;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\User\CurrentUser;

final class HomeController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly AccessService $access,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->responder->render('@web/Home/views/index', [], 'web');
    }

    /** Post-login landing: administrators go to the control center, everybody else to the web app. */
    public function redirect(): ResponseInterface
    {
        if ($this->access->can($this->currentUser->getId(), 'admin.access')) {
            return $this->responder->redirect('admin.dashboard');
        }
        return $this->responder->redirect('web.home');
    }
}
