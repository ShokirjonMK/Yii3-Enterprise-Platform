<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Platform\Identity\Application\MfaService;
use App\Platform\Identity\Domain\Identity;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Platform\Identity\Mfa\MfaManager;
use App\Platform\Identity\Mfa\MfaMethodType;
use App\Platform\Identity\Mfa\TelegramLink;
use App\Platform\Settings\SettingsService;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\TooManyRequestsException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Context\RequestContext;
use App\Shared\Infrastructure\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/**
 * Enrolment of second factors — used both by the mandatory "mfa_setup" login gate and from Account → Security:
 * authenticator app (QR), Telegram (one button + deep link), e-mail / SMS / voice (verify the address with a code),
 * passkey (WebAuthn), E-IMZO (sign a challenge). Only methods allowed by the user's role policy are offered.
 *
 * Author: ShokirjonMK
 */
final class MfaSetupController
{
    private const TOTP_SECRET = 'mfa.setup.secret';
    private const OTP_TARGET = 'mfa.setup.target';
    private const PASSKEY_CHALLENGE = 'mfa.setup.webauthn';

    public function __construct(
        private readonly Responder $responder,
        private readonly CurrentUser $currentUser,
        private readonly UserRepository $users,
        private readonly MfaService $totp,
        private readonly MfaManager $mfa,
        private readonly TelegramLink $telegram,
        private readonly WebAuthSession $webAuth,
        private readonly SessionInterface $session,
        private readonly RequestContext $context,
        private readonly SettingsService $settings,
        private readonly SecondFactor $secondFactor,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $policy = $this->mfa->policy($user);
        $enrolled = $this->mfa->enrolled($user);
        $enrolledTypes = array_column($enrolled, 'type');
        $selected = (string) ($request->getQueryParams()['method'] ?? '');
        if (!$policy->allows($selected)) {
            $selected = '';
            // the authenticator app first: it works everywhere without any provider
            foreach (array_unique([MfaMethodType::TOTP, ...$policy->allowed]) as $t) {
                if (!$policy->allows($t)) {
                    continue;
                }
                if (!in_array($t, $enrolledTypes, true)) {
                    $selected = $t;
                    break;
                }
            }
            $selected = $selected ?: ($policy->allowed[0] ?? '');
        }

        $params = [
            'user' => $user,
            'allowed' => $policy->allowed,
            'enrolled' => $enrolled,
            'enrolledTypes' => $enrolledTypes,
            'selected' => $selected,
            'forced' => in_array('mfa_setup', $this->webAuth->gates(), true),
            'required' => $policy->required,
        ];
        if ($selected === MfaMethodType::TOTP) {
            $secret = $this->session->get(self::TOTP_SECRET);
            if (!is_string($secret)) {
                $secret = $this->totp->generateSecret();
                $this->session->set(self::TOTP_SECRET, $secret);
            }
            $params['secret'] = $secret;
            $params['qr'] = $this->totp->qrSvg($this->totp->provisioningUri($secret, $user->username));
        }
        if (in_array($selected, [MfaMethodType::EMAIL, MfaMethodType::SMS, MfaMethodType::VOICE], true)) {
            $pending = $this->session->get(self::OTP_TARGET);
            $params['pending'] = is_array($pending) && ($pending['type'] ?? '') === $selected ? $pending : null;
            $params['defaultTarget'] = $selected === MfaMethodType::EMAIL ? (string) $user->email : (string) $user->phone;
        }
        if ($selected === MfaMethodType::TELEGRAM) {
            $link = $this->session->get('mfa.setup.telegram');
            $params['telegramLink'] = is_array($link) && ($link['at'] ?? 0) > time() - 600 ? $link : null;
        }
        $response = $this->responder->render('@web/Auth/views/mfa-setup', $params, 'auth');
        return $selected === MfaMethodType::EIMZO ? $response->withHeader('X-CSP-Connect', SecondFactor::EIMZO_CONNECT) : $response;
    }

    /** Authenticator app: confirm the first code (route kept from the TOTP-only version). */
    public function confirmTotp(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $secret = $this->session->get(self::TOTP_SECRET);
        $code = (string) (((array) $request->getParsedBody())['code'] ?? '');
        if (!is_string($secret) || !$this->mfa->policy($user)->allows(MfaMethodType::TOTP)) {
            return $this->responder->redirect('auth.mfa.setup');
        }
        try {
            $codes = $this->mfa->enrolTotp($user, $secret, $code);
        } catch (ValidationException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('auth.mfa.setup', [], ['method' => 'totp']);
        }
        $this->session->remove(self::TOTP_SECRET);
        return $this->done($user, MfaMethodType::TOTP, $codes);
    }

    /** Telegram: create the one-time deep link. */
    public function telegram(): ResponseInterface
    {
        $user = $this->user();
        if (!$this->mfa->policy($user)->allows(MfaMethodType::TELEGRAM)) {
            return $this->responder->redirect('auth.mfa.setup');
        }
        try {
            $link = $this->telegram->start($user->id);
        } catch (Throwable $e) {
            $this->responder->error('Telegram bot is not available: ' . $e->getMessage());
            return $this->responder->redirect('auth.mfa.setup', [], ['method' => 'telegram']);
        }
        $this->session->set('mfa.setup.telegram', ['url' => $link['url'], 'bot' => $link['bot'], 'at' => time()]);
        return $this->responder->redirect('auth.mfa.setup', [], ['method' => 'telegram']);
    }

    /** Telegram: polled by the page (HTMX every 2 s) until the bot has received /start. */
    public function telegramStatus(): ResponseInterface
    {
        $user = $this->user();
        $status = $this->telegram->status($user->id);
        if ($status === 'linked') {
            $this->session->remove('mfa.setup.telegram');
            $codes = $this->mfa->added($user, MfaMethodType::TELEGRAM);
            if ($codes !== null) {
                $this->session->set('mfa.recovery.show', $codes);
            }
            $this->afterEnrol($user);
            $this->responder->success('Telegram is connected.');
            $target = $codes !== null ? '/auth/mfa/recovery' : $this->urlNext();
            return $this->responder->fragment('@web/Auth/views/_telegram-status', ['status' => 'linked'])->withHeader('HX-Redirect', $target);
        }
        $response = $this->responder->fragment('@web/Auth/views/_telegram-status', ['status' => $status]);
        if ($status === 'expired') {
            $this->session->remove('mfa.setup.telegram');
            return $response->withStatus(286); // htmx: stop polling
        }
        return $response;
    }

    /** E-mail / SMS / voice: send a code to the entered address. */
    public function otpSend(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $body = (array) $request->getParsedBody();
        $type = (string) ($body['method'] ?? '');
        $target = (string) ($body['target'] ?? '');
        if ($target === '' && is_array($p = $this->session->get(self::OTP_TARGET)) && ($p['type'] ?? '') === $type) {
            $target = (string) $p['target']; // "send again"
        }
        try {
            $r = $this->mfa->startEnrolment($user, $type, $target);
            $this->session->set(self::OTP_TARGET, ['type' => $type, 'target' => $r['target'], 'hint' => $r['hint'], 'at' => time()]);
            $this->responder->success('The code has been sent.');
        } catch (ValidationException|ConflictException|TooManyRequestsException|RuntimeException $e) {
            $this->responder->error($e->getMessage());
        }
        return $this->responder->redirect('auth.mfa.setup', [], ['method' => $type]);
    }

    public function otpVerify(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $body = (array) $request->getParsedBody();
        $pending = $this->session->get(self::OTP_TARGET);
        if (!is_array($pending)) {
            return $this->responder->redirect('auth.mfa.setup');
        }
        try {
            $codes = $this->mfa->confirmEnrolment($user, (string) $pending['type'], (string) $pending['target'], (string) ($body['code'] ?? ''));
        } catch (ValidationException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('auth.mfa.setup', [], ['method' => (string) $pending['type']]);
        }
        $this->session->remove(self::OTP_TARGET);
        return $this->done($user, (string) $pending['type'], $codes);
    }

    public function passkeyOptions(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $name = trim((string) $this->settings->get('mfa.webauthn_rp_name')) ?: (string) $this->settings->get('app.name');
        $o = $this->mfa->passkeyRegistration($user, SecondFactor::rpId($request), $name);
        $this->session->set(self::PASSKEY_CHALLENGE, $o['challenge']);
        return $this->responder->json($o['options']);
    }

    public function passkey(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $body = (array) $request->getParsedBody();
        $challenge = $this->session->get(self::PASSKEY_CHALLENGE);
        $this->session->remove(self::PASSKEY_CHALLENGE);
        $credential = json_decode((string) ($body['credential'] ?? ''), true);
        try {
            if (!is_string($challenge) || !is_array($credential)) {
                throw ValidationException::single('credential', 'The passkey registration was cancelled or has expired. Try again.');
            }
            $codes = $this->mfa->registerPasskey($user, $credential, $challenge, SecondFactor::origin($request), SecondFactor::rpId($request), (string) ($body['label'] ?? ''));
        } catch (ValidationException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('auth.mfa.setup', [], ['method' => 'webauthn']);
        }
        return $this->done($user, MfaMethodType::WEBAUTHN, $codes);
    }

    public function eimzoChallenge(): ResponseInterface
    {
        try {
            return $this->responder->json($this->secondFactor->eimzoChallenge());
        } catch (RuntimeException $e) {
            return $this->responder->json(['error' => $e->getMessage()], 503);
        }
    }

    public function eimzo(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        try {
            $codes = $this->mfa->linkEimzo($user, (string) (((array) $request->getParsedBody())['pkcs7'] ?? ''), (string) $this->context->ip(), SecondFactor::rpId($request));
        } catch (ValidationException|ConflictException $e) {
            $this->responder->error($e->getMessage());
            return $this->responder->redirect('auth.mfa.setup', [], ['method' => 'eimzo']);
        }
        return $this->done($user, MfaMethodType::EIMZO, $codes);
    }

    /** One-time display of recovery codes produced in a background step (Telegram). */
    public function recovery(): ResponseInterface
    {
        $codes = $this->session->get('mfa.recovery.show');
        $this->session->remove('mfa.recovery.show');
        if (!is_array($codes)) {
            return $this->responder->redirectToUrl($this->urlNext());
        }
        return $this->responder->render('@web/Auth/views/recovery-codes', ['codes' => $codes, 'next' => $this->routeNext()], 'auth');
    }

    /** @param list<string>|null $codes */
    private function done(User $user, string $type, ?array $codes): ResponseInterface
    {
        $next = $this->afterEnrol($user);
        if ($codes !== null) {
            return $this->responder->render('@web/Auth/views/recovery-codes', ['codes' => $codes, 'next' => $next], 'auth');
        }
        $this->responder->success(sprintf('%s is now set up for two-factor sign-in.', MfaMethodType::label($type)));
        return $this->responder->redirect($next);
    }

    /** Clears the enrolment gate when the policy is satisfied; returns the route to continue with. */
    private function afterEnrol(User $user): string
    {
        $fresh = $this->users->find($user->id) ?? $user;
        $forced = in_array('mfa_setup', $this->webAuth->gates(), true);
        if ($forced && !$this->mfa->setupRequired($fresh)) {
            $this->webAuth->clearGate('mfa_setup');
        }
        $this->webAuth->markReauthenticated();
        $next = $forced ? $this->webAuth->nextRoute() : 'web.security';
        $this->session->set('mfa.setup.next', $next);
        return $next;
    }

    private function routeNext(): string
    {
        $next = $this->session->get('mfa.setup.next');
        return is_string($next) ? $next : ($this->webAuth->gates() === [] ? 'web.security' : $this->webAuth->nextRoute());
    }

    private function urlNext(): string
    {
        return match ($this->routeNext()) {
            'web.security' => '/account/security',
            'auth.mfa.setup' => '/auth/mfa/setup',
            'auth.password.change' => '/auth/password',
            default => '/',
        };
    }

    private function user(): User
    {
        $identity = $this->currentUser->getIdentity();
        assert($identity instanceof Identity);
        return $this->users->find($identity->user->id) ?? $identity->user;
    }
}
