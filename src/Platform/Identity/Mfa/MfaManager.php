<?php

declare(strict_types=1);

namespace App\Platform\Identity\Mfa;

use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Application\MfaService;
use App\Platform\Identity\Domain\User;
use App\Platform\Identity\Mfa\WebAuthn\WebAuthnServer;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use SensitiveParameter;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * One entry point for every second factor: list what a user has, enrol, verify, remove.
 * The web/API controllers only move data between the browser and this service.
 *
 * Author: ShokirjonMK
 */
final class MfaManager
{
    public function __construct(
        private readonly MfaService $totp,
        private readonly MfaMethodRepository $methods,
        private readonly MfaPolicyService $policies,
        private readonly OtpService $otp,
        private readonly WebAuthnServer $webauthn,
        private readonly EimzoClient $eimzo,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
    ) {}

    public function policy(User $user): MfaPolicy
    {
        return $this->policies->forUser($user);
    }

    /**
     * Enrolled factors. The authenticator app is reported as a pseudo-row with id "totp".
     *
     * @return list<array{id:string,type:string,label:string,target_hint:string,meta:array,created_at:?string,last_used_at:?string}>
     */
    public function enrolled(User $user): array
    {
        $out = [];
        if ($user->mfaSecretEnc !== null) {
            $out[] = ['id' => 'totp', 'type' => MfaMethodType::TOTP, 'label' => MfaMethodType::label(MfaMethodType::TOTP), 'target_hint' => '', 'meta' => [], 'created_at' => null, 'last_used_at' => null];
        }
        foreach ($this->methods->forUser($user->id) as $m) {
            $out[] = $m;
        }
        usort($out, static fn(array $a, array $b) => array_search($a['type'], MfaMethodType::ALL, true) <=> array_search($b['type'], MfaMethodType::ALL, true));
        return $out;
    }

    /**
     * Factors usable at sign-in: enrolled ∩ allowed by policy. If the policy no longer allows any enrolled method
     * (an administrator changed it), the enrolled methods that are still switched on remain usable so the user is
     * not locked out — they are then asked to enrol an allowed method (gate "mfa_setup").
     *
     * @return list<array>
     */
    public function usable(User $user, ?MfaPolicy $policy = null): array
    {
        $policy ??= $this->policy($user);
        $enrolled = $this->enrolled($user);
        $allowed = array_values(array_filter($enrolled, static fn(array $m) => $policy->allows($m['type'])));
        if ($allowed !== []) {
            return $allowed;
        }
        return array_values(array_filter($enrolled, fn(array $m) => $this->policies->enabled($m['type'])));
    }

    /** Does the user need to enrol (another) method allowed by the policy? */
    public function setupRequired(User $user, ?MfaPolicy $policy = null): bool
    {
        $policy ??= $this->policy($user);
        if (!$policy->required) {
            return false;
        }
        foreach ($this->enrolled($user) as $m) {
            if ($policy->allows($m['type'])) {
                return false;
            }
        }
        return true;
    }

    public function preferred(User $user, array $usable): ?array
    {
        $pref = (string) ($this->db->createCommand('SELECT mfa_preferred FROM users WHERE id = :id', [':id' => $user->id])->queryScalar() ?: '');
        foreach ($usable as $m) {
            if ($m['type'] === $pref) {
                return $m;
            }
        }
        return $usable[0] ?? null;
    }

    public function setPreferred(string $userId, string $type): void
    {
        if (MfaMethodType::isValid($type)) {
            $this->db->createCommand()->update('users', ['mfa_preferred' => $type], ['id' => $userId])->execute();
        }
    }

    // ------------------------------------------------------------------ one-time codes

    /** Sends a sign-in / re-auth code over an enrolled OTP method. Returns the resend cooldown (s). */
    public function sendCode(User $user, string $type, string $purpose = 'login'): int
    {
        if (!MfaMethodType::isOtp($type)) {
            throw ValidationException::single('method', 'This method does not use sent codes.');
        }
        $m = $this->methods->findByType($user->id, $type) ?? throw new NotFoundException('This method is not set up for your account.');
        return $this->otp->send($user, $type, $this->methods->target($m), $purpose);
    }

    /** Starts enrolment of e-mail / SMS / voice: sends a code to the new target. */
    public function startEnrolment(User $user, string $type, string $target): array
    {
        $policy = $this->policy($user);
        if (!in_array($type, [MfaMethodType::EMAIL, MfaMethodType::SMS, MfaMethodType::VOICE], true) || !$policy->allows($type)) {
            throw ValidationException::single('method', 'This method is not available for your account.');
        }
        $target = trim($target);
        if ($type === MfaMethodType::EMAIL) {
            if (filter_var($target, FILTER_VALIDATE_EMAIL) === false || mb_strlen($target) > 254) {
                throw ValidationException::single('target', 'Enter a valid e-mail address.');
            }
            $target = mb_strtolower($target);
        } else {
            $target = MfaMethodType::normalizePhone($target) ?? throw ValidationException::single('target', 'Enter the phone number in international format, e.g. +998901234567.');
        }
        if ($this->methods->boundToOther($user->id, $type, $target)) {
            throw new ConflictException('This address is already used for two-factor sign-in by another account.', 'mfa_target_taken');
        }
        $cooldown = $this->otp->send($user, $type, $target, 'enrol');
        return ['target' => $target, 'hint' => MfaMethodType::mask($type, $target), 'cooldown' => $cooldown];
    }

    /** @return list<string>|null fresh recovery codes when 2FA has just been switched on */
    public function confirmEnrolment(User $user, string $type, string $target, #[SensitiveParameter] string $code): ?array
    {
        if (!$this->otp->verify($user->id, $type, $target, 'enrol', $code)) {
            throw ValidationException::single('code', 'The code is not valid or has expired.');
        }
        $this->methods->save($user->id, $type, $target, MfaMethodType::mask($type, $target));
        return $this->added($user, $type);
    }

    // ------------------------------------------------------------------ authenticator app

    /** @return list<string>|null */
    public function enrolTotp(User $user, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): ?array
    {
        if ($this->totp->verifyWithSecret($secret, $code) === null) {
            throw ValidationException::single('code', 'The code is not valid. Check the time on your device and try again.');
        }
        $hadMfa = $user->mfaEnabled && $user->mfaRecoveryCodes !== [];
        $codes = $this->totp->enable($user->id, $secret, keepRecoveryCodes: $hadMfa);
        $this->audit->security('auth.mfa.enabled', 'success', ['method' => 'totp'], 'notice', $user->id, $user->username);
        $this->audit->security('auth.mfa.method_added', 'success', ['method' => 'totp'], 'notice', $user->id, $user->username);
        return $hadMfa ? null : $codes;
    }

    // ------------------------------------------------------------------ passkeys

    /** @return array{options:array, challenge:string} */
    public function passkeyRegistration(User $user, string $rpId, string $rpName): array
    {
        $exclude = array_map(fn(array $m) => $this->methods->target($m), array_filter($this->methods->forUser($user->id), static fn(array $m) => $m['type'] === MfaMethodType::WEBAUTHN));
        return $this->webauthn->registrationOptions($rpId, $rpName, $user->id, $user->username, $user->displayName(), $exclude);
    }

    /** @return list<string>|null */
    public function registerPasskey(User $user, array $credential, string $challenge, string $origin, string $rpId, string $label): ?array
    {
        $policy = $this->policy($user);
        if (!$policy->allows(MfaMethodType::WEBAUTHN)) {
            throw ValidationException::single('method', 'This method is not available for your account.');
        }
        $c = $this->webauthn->verifyRegistration($credential, $challenge, $origin, $rpId);
        $label = trim($label) !== '' ? trim($label) : 'Passkey ' . date('Y-m-d');
        $this->methods->save($user->id, MfaMethodType::WEBAUTHN, $c['id'], $label,
            ['alg' => $c['alg'], 'aaguid' => $c['aaguid'], 'transports' => $c['transports'], 'uv' => $c['uv']], $c['public_key'],
            hint: $c['uv'] ? 'biometric / PIN' : 'presence', signCount: $c['sign_count']);
        return $this->added($user, MfaMethodType::WEBAUTHN);
    }

    /** @return array{options:array, challenge:string} */
    public function passkeyAssertion(User $user, string $rpId): array
    {
        $creds = [];
        foreach ($this->methods->forUser($user->id) as $m) {
            if ($m['type'] === MfaMethodType::WEBAUTHN) {
                $creds[] = ['id' => $this->methods->target($m), 'transports' => (array) ($m['meta']['transports'] ?? [])];
            }
        }
        if ($creds === []) {
            throw new NotFoundException('No passkey is registered for this account.');
        }
        return $this->webauthn->assertionOptions($rpId, $creds);
    }

    // ------------------------------------------------------------------ E-IMZO

    public function eimzoChallenge(): string
    {
        return $this->eimzo->challenge();
    }

    /** @return list<string>|null */
    public function linkEimzo(User $user, string $pkcs7, string $ip, string $host): ?array
    {
        if (!$this->policy($user)->allows(MfaMethodType::EIMZO)) {
            throw ValidationException::single('method', 'This method is not available for your account.');
        }
        try {
            $info = $this->eimzo->authenticate($pkcs7, $ip, $host);
        } catch (\RuntimeException $e) {
            throw ValidationException::single('pkcs7', $e->getMessage());
        }
        $identity = EimzoClient::identity($info);
        if ($this->methods->boundToOther($user->id, MfaMethodType::EIMZO, $identity)) {
            throw new ConflictException('This E-IMZO key holder is already linked to another account.', 'mfa_target_taken');
        }
        $label = trim($info['name'] . ($info['organization'] !== '' ? ' — ' . $info['organization'] : ''));
        $this->methods->save($user->id, MfaMethodType::EIMZO, $identity, $label ?: 'E-IMZO',
            ['serial' => $info['serial'], 'valid_to' => $info['valid_to'], 'tin' => $info['tin']],
            hint: $info['pinfl'] !== '' ? 'PINFL ••••••••••' . substr($info['pinfl'], -4) : 'TIN ' . $info['tin']);
        return $this->added($user, MfaMethodType::EIMZO);
    }

    // ------------------------------------------------------------------ verification

    /**
     * Verifies a second factor.
     *
     * @param array{code?:string, credential?:array, challenge?:string, origin?:string, rp_id?:string, pkcs7?:string, ip?:string, host?:string} $input
     */
    public function verify(User $user, string $type, array $input, string $purpose = 'login'): bool
    {
        if ($type === 'recovery') {
            $ok = $this->totp->verifyRecoveryCode($user, (string) ($input['code'] ?? ''));
            if ($ok) {
                $this->audit->security('auth.mfa.recovery_code_used', 'success', ['left' => max(0, count($user->mfaRecoveryCodes) - 1)], 'warning', $user->id, $user->username);
            }
            return $ok;
        }
        $usable = array_column($this->usable($user), null, 'type');
        if (!isset($usable[$type])) {
            return false;
        }
        $ok = match (true) {
            $type === MfaMethodType::TOTP => $this->totp->verifyTotp($user, (string) ($input['code'] ?? '')),
            MfaMethodType::isOtp($type) => $this->verifyOtp($user, $type, (string) ($input['code'] ?? ''), $purpose),
            $type === MfaMethodType::WEBAUTHN => $this->verifyPasskey($user, $input),
            $type === MfaMethodType::EIMZO => $this->verifyEimzo($user, $input),
            default => false,
        };
        if ($ok && $type !== MfaMethodType::WEBAUTHN && $usable[$type]['id'] !== 'totp') {
            $this->methods->touch($usable[$type]['id']);
        }
        return $ok;
    }

    // ------------------------------------------------------------------ removal

    public function remove(User $user, string $methodId): void
    {
        $policy = $this->policy($user);
        $enrolled = $this->enrolled($user);
        $target = null;
        foreach ($enrolled as $m) {
            if ($m['id'] === $methodId) {
                $target = $m;
            }
        }
        if ($target === null) {
            throw new NotFoundException('Method not found.');
        }
        if ($policy->required) {
            $remaining = array_filter($enrolled, static fn(array $m) => $m['id'] !== $methodId && $policy->allows($m['type']));
            if ($remaining === []) {
                throw new ConflictException('Two-factor authentication is mandatory for your account: add another method before removing this one.', 'mfa_last_method');
            }
        }
        if ($methodId === 'totp') {
            $this->totp->removeTotp($user->id);
        } else {
            $this->methods->delete($user->id, $methodId);
        }
        $this->totp->refresh($user->id);
        $this->audit->security('auth.mfa.method_removed', 'success', ['method' => $target['type']], 'notice', $user->id, $user->username);
    }

    /** Administrator reset: removes every factor, recovery codes and remembered devices. */
    public function resetAll(string $userId): void
    {
        $this->totp->disable($userId);
    }

    /** Called after any new factor was saved. @return list<string>|null */
    public function added(User $user, string $type): ?array
    {
        $codes = $this->totp->refresh($user->id);
        if ($type !== MfaMethodType::TOTP && $type !== MfaMethodType::TELEGRAM) {
            $this->audit->security('auth.mfa.method_added', 'success', ['method' => $type], 'notice', $user->id, $user->username);
        }
        return $codes;
    }

    private function verifyOtp(User $user, string $type, string $code, string $purpose): bool
    {
        $m = $this->methods->findByType($user->id, $type);
        return $m !== null && $this->otp->verify($user->id, $type, $this->methods->target($m), $purpose, $code);
    }

    private function verifyPasskey(User $user, array $input): bool
    {
        $credential = (array) ($input['credential'] ?? []);
        $id = (string) ($credential['id'] ?? '');
        foreach ($this->methods->forUser($user->id) as $m) {
            if ($m['type'] !== MfaMethodType::WEBAUTHN || !hash_equals($this->methods->target($m), $id)) {
                continue;
            }
            try {
                $count = $this->webauthn->verifyAssertion($credential, (string) ($input['challenge'] ?? ''), (string) ($input['origin'] ?? ''),
                    (string) ($input['rp_id'] ?? ''), $this->methods->secret($m), (int) ($m['meta']['alg'] ?? WebAuthnServer::ALG_ES256), $m['sign_count']);
            } catch (ValidationException) {
                return false;
            }
            $this->methods->touch($m['id'], $count);
            return true;
        }
        return false;
    }

    private function verifyEimzo(User $user, array $input): bool
    {
        $m = $this->methods->findByType($user->id, MfaMethodType::EIMZO);
        if ($m === null) {
            return false;
        }
        try {
            $info = $this->eimzo->authenticate((string) ($input['pkcs7'] ?? ''), (string) ($input['ip'] ?? ''), (string) ($input['host'] ?? ''));
        } catch (\RuntimeException) {
            return false;
        }
        return hash_equals($this->methods->target($m), EimzoClient::identity($info));
    }
}
