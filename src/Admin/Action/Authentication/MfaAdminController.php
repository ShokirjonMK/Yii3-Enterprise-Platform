<?php

declare(strict_types=1);

namespace App\Admin\Action\Authentication;

use App\Admin\Action\Users\AdminActor;
use App\Platform\Access\AccessService;
use App\Platform\Access\RoleManager;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Mfa\EimzoClient;
use App\Platform\Identity\Mfa\MfaMethodRepository;
use App\Platform\Identity\Mfa\MfaMethodType;
use App\Platform\Identity\Mfa\MfaPolicyService;
use App\Platform\Identity\Mfa\OtpDelivery;
use App\Platform\Identity\Mfa\TelegramLink;
use App\Platform\Notifications\Channel\EmailChannel;
use App\Platform\Notifications\Channel\SmsChannel;
use App\Platform\Notifications\Channel\TelegramChannel;
use App\Platform\Settings\SettingsForm;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Http\Responder;
use App\Web\Auth\SecondFactor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\CurrentRoute;

/**
 * 03 · Authentication → Two-factor: switch methods on/off, provider settings, per-role policies
 * (who must use 2FA, allowed methods, remember-device period), connection checks, Telegram webhook.
 * Policies of administrative roles and the method switches need a superadmin; everything is audited.
 *
 * Author: ShokirjonMK
 */
final class MfaAdminController
{
    /** Settings edited on the Two-factor page (provider credentials of e-mail/SMS/Telegram live in Notifications). */
    public const SETTINGS = [
        'One-time codes' => ['mfa.code_length', 'mfa.code_ttl_seconds', 'mfa.code_max_attempts', 'mfa.resend_seconds', 'mfa.max_sends_per_hour'],
        'Telegram' => ['mfa.telegram_bot_username', 'mfa.telegram_update_mode'],
        'Voice call' => ['mfa.voice_provider', 'mfa.voice_language', 'mfa.voice_from', 'mfa.voice_twilio_sid', 'mfa.voice_twilio_token', 'mfa.voice_http_url', 'mfa.voice_http_token'],
        'E-IMZO' => ['mfa.eimzo_server_url', 'mfa.eimzo_api_keys'],
        'Passkeys' => ['mfa.webauthn_rp_name'],
    ];

    /** Where the credentials of each delivery channel are edited. */
    private const PROVIDER_ROUTE = [
        'telegram' => ['admin.notifications', 'Telegram bot token: Notifications → Telegram'],
        'email' => ['admin.notifications', 'SMTP: Notifications → E-mail'],
        'sms' => ['admin.notifications', 'SMS gateway: Notifications → SMS'],
    ];

    public function __construct(
        private readonly Responder $responder,
        private readonly AdminActor $actor,
        private readonly AccessService $access,
        private readonly MfaPolicyService $policies,
        private readonly MfaMethodRepository $methods,
        private readonly RoleManager $roles,
        private readonly SettingsService $settings,
        private readonly SettingsForm $form,
        private readonly AuditLogger $audit,
        private readonly TelegramLink $telegram,
        private readonly TelegramChannel $telegramChannel,
        private readonly EmailChannel $email,
        private readonly SmsChannel $sms,
        private readonly EimzoClient $eimzo,
        private readonly OtpDelivery $delivery,
        private readonly ConnectionInterface $db,
    ) {}

    public function index(): ResponseInterface
    {
        $actor = $this->actor->user();
        $usage = $this->methods->usage();
        $methods = [];
        foreach (MfaMethodType::ALL as $t) {
            $methods[$t] = [
                'enabled' => $this->policies->enabled($t),
                'configured' => $this->policies->configured($t),
                'users' => $usage[$t] ?? 0,
                'provider' => self::PROVIDER_ROUTE[$t] ?? null,
            ];
        }
        $roles = $this->roles->roles();
        $policies = $this->policies->all();
        $total = (int) (new Query($this->db))->from('users')->where(['deleted_at' => null, 'status' => 'active'])->count();
        $withMfa = (int) (new Query($this->db))->from('users')->where(['deleted_at' => null, 'status' => 'active', 'mfa_enabled' => true])->count();
        return $this->responder->render('admin/auth/mfa', [
            'methods' => $methods,
            'policies' => $policies,
            'roles' => $roles,
            'mayEdit' => array_combine(array_keys($policies), array_map(fn(string $s) => $this->policies->mayEdit($actor->id, $s), array_keys($policies))),
            'isSuper' => $this->access->isSuperadmin($actor->id),
            'coverage' => ['total' => $total, 'mfa' => $withMfa],
            'telegramMode' => $this->telegram->mode(),
        ]);
    }

    /** Method on/off — a superadmin decision (switching off a method may force users to re-enrol). */
    public function toggle(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $type = $this->type($route);
        if (!$this->access->isSuperadmin($actor->id)) {
            throw new AccessDeniedException('Only a superadmin can switch two-factor methods on or off.');
        }
        $on = !$this->policies->enabled($type);
        if (!$on && $type === MfaMethodType::TOTP && !$this->policies->enabled(MfaMethodType::WEBAUTHN)) {
            $this->responder->error('Keep the authenticator app or passkeys switched on: they need no external provider and are the fallback for administrators.');
            return $this->responder->redirect('admin.auth.mfa');
        }
        $this->settings->set($actor->id, "mfa.{$type}_enabled", $on, comment: 'two-factor method switch', permission: 'auth.manage');
        $this->responder->success(sprintf('%s: %s.', MfaMethodType::label($type), $on ? 'enabled' : 'disabled'));
        return $this->responder->redirect('admin.auth.mfa');
    }

    public function savePolicy(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $b = AdminActor::body($request);
        $subject = trim((string) ($b['subject'] ?? ''));
        $allowed = array_values(array_filter((array) ($b['allowed'] ?? []), 'is_string'));
        try {
            $this->policies->save($actor->id, $subject, !empty($b['required']), $allowed, (int) ($b['remember_days'] ?? 0));
            $this->responder->success(sprintf('Two-factor policy for "%s" saved.', $subject));
        } catch (ValidationException|NotFoundException|AccessDeniedException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.auth.mfa');
    }

    public function deletePolicy(CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $subject = (string) $route->getArgument('subject');
        try {
            $this->policies->delete($actor->id, $subject);
            $this->responder->success(sprintf('Policy "%s" removed: the role now follows the default policy.', $subject));
        } catch (ValidationException|NotFoundException|AccessDeniedException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('admin.auth.mfa');
    }

    public function settingsForm(): ResponseInterface
    {
        return $this->settingsPage([]);
    }

    public function saveSettings(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        try {
            $changed = $this->form->save($actor->id, array_merge(...array_values(self::SETTINGS)), AdminActor::body($request), 'auth.manage', 'two-factor settings');
        } catch (ValidationException $e) {
            return $this->settingsPage($e->errors(), 422);
        }
        $this->responder->success(sprintf('Saved (%d change(s)).', count($changed)));
        return $this->responder->redirect('admin.auth.mfa.settings');
    }

    /** Provider check; with a target it also sends a sample code ("123456") to it. */
    public function check(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        $actor = $this->actor->user();
        $type = $this->type($route);
        $target = trim((string) (AdminActor::body($request)['target'] ?? ''));
        $health = match ($type) {
            MfaMethodType::TELEGRAM => $this->telegramChannel->health(),
            MfaMethodType::EMAIL => $this->email->health(),
            MfaMethodType::SMS => $this->sms->health(),
            MfaMethodType::EIMZO => $this->eimzo->health(),
            MfaMethodType::VOICE => ['status' => $this->policies->configured($type) ? 'configured' : 'unconfigured', 'details' => ['note' => 'a call is placed only by a test send']],
            default => ['status' => 'healthy', 'details' => ['note' => 'no external provider']],
        };
        $result = $health['status'] . ($health['details'] !== [] ? ' — ' . json_encode($health['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        if ($target !== '' && MfaMethodType::isOtp($type)) {
            try {
                if (in_array($type, [MfaMethodType::SMS, MfaMethodType::VOICE], true)) {
                    $target = MfaMethodType::normalizePhone($target) ?? throw new \RuntimeException('Invalid phone number.');
                }
                $this->delivery->deliver($type, $target, '123456', 5, $actor);
                $result .= ' · test code sent to ' . MfaMethodType::mask($type, $target);
                $this->audit->record('auth.mfa.provider_tested', 'mfa_method', $type, null, null, ['target' => MfaMethodType::mask($type, $target)], category: 'security', severity: 'info', actorId: $actor->id);
            } catch (Throwable $e) {
                $result .= ' · test send failed: ' . ($e->getPrevious()?->getMessage() ?? $e->getMessage());
            }
        }
        $ok = in_array($health['status'], ['healthy', 'configured'], true) && !str_contains($result, 'failed');
        $ok ? $this->responder->success(MfaMethodType::label($type) . ': ' . $result) : $this->responder->error(MfaMethodType::label($type) . ': ' . $result);
        return $this->responder->redirect('admin.auth.mfa');
    }

    /** Telegram webhook on/off (webhook needs a public HTTPS URL; polling works everywhere). */
    public function telegramWebhook(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor->user();
        $action = (string) (AdminActor::body($request)['action'] ?? '');
        try {
            if ($action === 'set') {
                $url = SecondFactor::origin($request) . '/integrations/telegram/webhook';
                $secret = bin2hex(random_bytes(24));
                $this->telegram->setWebhook($url, $secret);
                $this->settings->set($actor->id, 'mfa.telegram_webhook_secret', $secret, comment: 'webhook registered', permission: 'auth.manage');
                $this->settings->set($actor->id, 'mfa.telegram_update_mode', 'webhook', comment: 'webhook registered', permission: 'auth.manage');
                $this->responder->success('Webhook registered: ' . $url);
            } elseif ($action === 'delete') {
                $this->telegram->deleteWebhook();
                $this->settings->set($actor->id, 'mfa.telegram_update_mode', 'polling', comment: 'webhook removed', permission: 'auth.manage');
                $this->responder->success('Webhook removed — the bot is polled for updates.');
            } else {
                $info = $this->telegram->webhookInfo();
                $this->responder->info('Webhook: ' . (($info['url'] ?? '') ?: 'none (polling)') . (isset($info['pending_update_count']) ? ' · pending updates: ' . $info['pending_update_count'] : '')
                    . (!empty($info['last_error_message']) ? ' · last error: ' . $info['last_error_message'] : ''));
            }
        } catch (Throwable $e) {
            $this->responder->error('Telegram: ' . $e->getMessage());
        }
        return $this->responder->redirect('admin.auth.mfa');
    }

    private function settingsPage(array $errors, int $status = 200): ResponseInterface
    {
        $sections = [];
        foreach (self::SETTINGS as $title => $keys) {
            $sections[$title] = $this->form->fields($keys);
        }
        return $this->responder->render('admin/auth/mfa-settings', ['sections' => $sections, 'errors' => $errors], status: $status);
    }

    private function type(CurrentRoute $route): string
    {
        $type = (string) $route->getArgument('type');
        if (!MfaMethodType::isValid($type)) {
            throw new NotFoundException('Unknown method.');
        }
        return $type;
    }
}
