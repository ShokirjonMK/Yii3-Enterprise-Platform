<?php

declare(strict_types=1);

namespace App\Admin\Authentication;

use App\Admin\Users\AdminActor;
use App\Platform\Settings\SettingsForm;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 03 · Authentication → Sessions & sign-in (/admin/auth/sessions): inactivity sign-out, maximum session length,
 * step-up validity, failed-password lockout. Read at runtime by SecurityPolicy (CurrentUser, SessionRegistry, Redis
 * sessions, RequireRecentAuth, LoginThrottle) — applies to the next request. Saving needs auth.manage + step-up;
 * every change is versioned and audited (settings history / rollback in System Settings).
 *
 * Author: ShokirjonMK
 */
final class SessionSettingsController
{
    public const SETTINGS = [
        'Sessions' => ['security.session_idle_minutes', 'security.session_absolute_hours', 'security.reauth_minutes'],
        'Failed sign-ins' => ['security.login_max_attempts', 'security.lockout_minutes', 'retention.login_attempts_days'],
    ];

    public function __construct(
        private readonly Responder $responder,
        private readonly SettingsForm $form,
        private readonly AdminActor $actor,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->page([]);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $changed = $this->form->save($this->actor->user()->id, array_merge(...array_values(self::SETTINGS)), AdminActor::body($request), 'auth.manage', 'Admin → Authentication → Sessions & sign-in');
        } catch (ValidationException $e) {
            return $this->page($e->errors(), 422);
        }
        $this->responder->success($changed === [] ? 'Nothing changed.' : sprintf('Saved (%d change(s)). New limits apply to the next request.', count($changed)));
        return $this->responder->redirect('admin.auth.sessions');
    }

    private function page(array $errors, int $status = 200): ResponseInterface
    {
        $sections = [];
        foreach (self::SETTINGS as $title => $keys) {
            $sections[$title] = $this->form->fields($keys);
        }
        return $this->responder->render('@admin/Authentication/views/mfa-settings', ['sections' => $sections, 'errors' => $errors, 'page' => 'sessions'], status: $status);
    }
}
