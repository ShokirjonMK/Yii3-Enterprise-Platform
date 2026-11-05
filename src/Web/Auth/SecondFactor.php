<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Mfa\MfaManager;
use App\Platform\Identity\Mfa\MfaMethodType;
use App\Platform\Identity\Mfa\OtpService;
use App\Platform\Settings\SettingsService;
use App\Shared\Infrastructure\Context\RequestContext;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;

/**
 * Browser side of a second-factor challenge, shared by the sign-in step (purpose "login") and step-up
 * re-authentication (purpose "reauth"): which methods to offer, sending codes, passkey/E-IMZO challenges
 * (kept single-use in the session) and verification of the submitted form.
 *
 * Author: ShokirjonMK
 */
final class SecondFactor
{
    public const EIMZO_CONNECT = 'wss://127.0.0.1:64443 ws://127.0.0.1:64646';

    public function __construct(
        private readonly MfaManager $mfa,
        private readonly OtpService $otp,
        private readonly SessionInterface $session,
        private readonly RequestContext $context,
        private readonly SettingsService $settings,
    ) {}

    /** View parameters for resources/views/auth/_second-factor.php */
    public function params(User $user, ?string $selected, string $purpose): array
    {
        $usable = $this->mfa->usable($user);
        $types = array_column($usable, 'type');
        if ($selected !== 'recovery' && !in_array($selected, $types, true)) {
            $selected = $this->mfa->preferred($user, $usable)['type'] ?? 'recovery';
        }
        $current = null;
        foreach ($usable as $m) {
            if ($m['type'] === $selected) {
                $current = $m;
            }
        }
        return [
            'methods' => $usable,
            'selected' => $selected,
            'current' => $current,
            'codeSent' => MfaMethodType::isOtp((string) $selected) && $this->otp->pending($user->id, (string) $selected, $purpose),
            'cooldown' => MfaMethodType::isOtp((string) $selected) ? $this->otp->cooldown($user->id, (string) $selected, $purpose) : 0,
            'recoveryLeft' => count($user->mfaRecoveryCodes),
            'purpose' => $purpose,
        ];
    }

    public function send(User $user, string $type, string $purpose): int
    {
        return $this->mfa->sendCode($user, $type, $purpose);
    }

    /** @return array<string,mixed> PublicKeyCredentialRequestOptions (JSON, base64url) */
    public function passkeyOptions(User $user, ServerRequestInterface $request, string $purpose): array
    {
        $o = $this->mfa->passkeyAssertion($user, self::rpId($request));
        $this->session->set("mfa.webauthn.$purpose", $o['challenge']);
        return $o['options'];
    }

    /** @return array{challenge:string, apiKeys:list<string>} */
    public function eimzoChallenge(): array
    {
        $keys = $this->settings->get('mfa.eimzo_api_keys');
        return ['challenge' => $this->mfa->eimzoChallenge(), 'apiKeys' => array_values(array_map('strval', is_array($keys) ? $keys : []))];
    }

    public function verify(User $user, ServerRequestInterface $request, string $purpose): bool
    {
        $body = (array) $request->getParsedBody();
        $type = (string) ($body['method'] ?? '');
        $code = (string) ($body['code'] ?? '');
        if ($type === '') {
            // legacy form (and API clients of the old login page): TOTP code or recovery code
            $type = preg_match('/^\s*\d{6}\s*$/', $code) === 1 ? MfaMethodType::TOTP : 'recovery';
            if ($type === MfaMethodType::TOTP && !in_array(MfaMethodType::TOTP, array_column($this->mfa->usable($user), 'type'), true)) {
                $type = 'recovery';
            }
        }
        $input = ['code' => $code];
        if ($type === MfaMethodType::WEBAUTHN) {
            $challenge = $this->session->get("mfa.webauthn.$purpose");
            $this->session->remove("mfa.webauthn.$purpose");
            $credential = json_decode((string) ($body['credential'] ?? ''), true);
            if (!is_string($challenge) || !is_array($credential)) {
                return false;
            }
            $input = ['credential' => $credential, 'challenge' => $challenge, 'origin' => self::origin($request), 'rp_id' => self::rpId($request)];
        } elseif ($type === MfaMethodType::EIMZO) {
            $input = ['pkcs7' => (string) ($body['pkcs7'] ?? ''), 'ip' => (string) $this->context->ip(), 'host' => self::rpId($request)];
        }
        $ok = $this->mfa->verify($user, $type, $input, $purpose);
        if ($ok && $type !== 'recovery') {
            $this->mfa->setPreferred($user->id, $type);
        }
        return $ok;
    }

    public static function rpId(ServerRequestInterface $request): string
    {
        return strtolower($request->getUri()->getHost());
    }

    public static function origin(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $port = $uri->getPort();
        return $uri->getScheme() . '://' . $uri->getHost() . ($port !== null ? ':' . $port : '');
    }
}
