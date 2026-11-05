<?php

declare(strict_types=1);

namespace App\Admin\Action\Settings;

use App\Admin\Action\Users\AdminActor;
use App\Platform\Access\AccessService;
use App\Platform\Appearance\AppearanceViewInjection;
use App\Platform\Appearance\ThemeRegistry;
use App\Platform\Appearance\ThemeService;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;

/**
 * 21 · System Settings → Appearance: theme gallery, preview (own session only), activate globally or per tenant,
 * let users pick their own theme, upload / delete theme packs, download a theme as a starter template.
 *
 * Author: ShokirjonMK
 */
final class AppearanceController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly AdminActor $actor,
        private readonly ThemeService $themes,
        private readonly AccessService $access,
        private readonly SessionInterface $session,
        private readonly ConnectionInterface $db,
    ) {}

    public function index(): ResponseInterface
    {
        $actor = $this->actor->user();
        $q = (new Query($this->db))->select(['id', 'name'])->from('tenants')->orderBy(['name' => SORT_ASC])->limit(500);
        if (!$this->access->can($actor->id, 'tenants.cross')) {
            $q->where(['id' => $actor->tenantId]);
        }
        $tenants = [];
        foreach ($q->all() as $t) {
            $tenants[(string) $t['id']] = (string) $t['name'];
        }
        $preview = $this->session->get(AppearanceViewInjection::PREVIEW);
        return $this->responder->render('admin/settings/appearance', [
            'themes' => $this->themes->registry()->all(),
            'settings' => $this->themes->settings(),
            'tenants' => $tenants,
            'preview' => is_string($preview) ? $preview : null,
        ]);
    }

    public function activate(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $code = (string) (AdminActor::body($request)['theme'] ?? '');
        try {
            $this->themes->activate($actor->id, $code);
            $this->session->remove(AppearanceViewInjection::PREVIEW);
            $this->responder->success(sprintf('Theme "%s" is now active for everyone.', $this->name($code)));
        } catch (NotFoundException|ValidationException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.appearance');
    }

    public function tenant(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $b = AdminActor::body($request);
        $tenant = (string) ($b['tenant_id'] ?? '');
        $code = (string) ($b['theme'] ?? '');
        $allowed = $this->access->can($actor->id, 'tenants.cross') || $tenant === (string) $actor->tenantId;
        try {
            if (!$allowed || (new Query($this->db))->from('tenants')->where(['id' => $tenant])->count() === 0) {
                throw new NotFoundException('Tenant not found.');
            }
            $this->themes->activate($actor->id, $code === '' ? null : $code, 'tenant', $tenant);
            $this->responder->success($code === '' ? 'The tenant follows the global theme again.' : sprintf('Tenant theme set to "%s".', $this->name($code)));
        } catch (NotFoundException|ValidationException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.appearance');
    }

    public function userChoice(ServerRequestInterface $request): ResponseInterface
    {
        $allow = !empty(AdminActor::body($request)['allow']);
        $this->themes->allowUserChoice($this->actor->user()->id, $allow);
        $this->responder->success($allow ? 'Users may choose their own theme.' : 'Everyone sees the organisation theme.');
        return $this->responder->redirect('admin.appearance');
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $file = $request->getUploadedFiles()['theme'] ?? null;
        $tmp = null;
        try {
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                throw ValidationException::single('file', 'Choose a theme .zip file.');
            }
            if (($file->getSize() ?? 0) > \App\Platform\Appearance\ThemeValidator::MAX_ZIP) {
                throw ValidationException::single('file', 'The theme archive is larger than 512 KB.');
            }
            $tmp = (string) tempnam(sys_get_temp_dir(), 'thm');
            file_put_contents($tmp, (string) $file->getStream());
            $m = $this->themes->installZip($actor->id, $tmp, !empty(AdminActor::body($request)['replace']));
            $this->responder->success(sprintf('Theme "%s" %s installed. Preview it, then activate.', $m['name'], $m['version']));
        } catch (ValidationException|ConflictException $e) {
            $this->responder->error($e->getMessage());
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
        return $this->responder->redirect('admin.appearance');
    }

    public function delete(CurrentRoute $route): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        try {
            $this->themes->delete($this->actor->user()->id, $code);
            $this->responder->success(sprintf('Theme "%s" deleted.', $code));
        } catch (NotFoundException|ConflictException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.appearance');
    }

    public function preview(CurrentRoute $route): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        if (!$this->themes->registry()->exists($code)) {
            throw new NotFoundException('Theme not found.');
        }
        $this->session->set(AppearanceViewInjection::PREVIEW, $code);
        return $this->responder->redirect('admin.appearance');
    }

    public function stopPreview(): ResponseInterface
    {
        $this->session->remove(AppearanceViewInjection::PREVIEW);
        return $this->responder->redirect('admin.appearance');
    }

    /** ?starter=1 renames the pack to "my-theme" so it can be edited and uploaded as a new theme. */
    public function download(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $code = (string) $route->getArgument('code');
        $starter = !empty($request->getQueryParams()['starter']);
        $zip = $this->themes->exportZip($code, $starter ? 'my-theme' : null);
        return $this->responder->download($zip, ($starter ? 'my-theme' : $code) . '-theme.zip', 'application/zip');
    }

    private function name(string $code): string
    {
        return (string) ($this->themes->registry()->get($code)['name'] ?? $code);
    }
}
