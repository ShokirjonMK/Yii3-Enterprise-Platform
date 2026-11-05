<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application;

use App\Platform\Settings\SettingsService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Session and sign-in limits, managed in Admin → Authentication → Sessions & sign-in (settings, versioned + audited):
 *
 *   security.session_idle_minutes    sign-out after inactivity              (default 30)
 *   security.session_absolute_hours  maximum session length                 (default 12)
 *   security.reauth_minutes          step-up confirmation stays valid       (default 5)
 *   security.login_max_attempts      failed passwords before lockout        (default 5)
 *   security.lockout_minutes         lockout duration                       (default 15)
 *
 * Values are read per call (settings are cached per config revision), so a change applies to the next request without a
 * restart. Defaults (config/common/params.php platform.security) are used when the settings table is not reachable yet
 * (install, migrations). SettingsService is resolved lazily: it is not needed to build CurrentUser / sessions.
 *
 * Author: ShokirjonMK
 */
final class SecurityPolicy
{
    private ?SettingsService $settings = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly int $idleSeconds = 1800,
        private readonly int $absoluteSeconds = 43200,
        private readonly int $reauthSeconds = 300,
        private readonly int $maxAttempts = 5,
        private readonly int $lockoutSeconds = 900,
    ) {}

    public function sessionIdleSeconds(): int
    {
        return $this->minutes('security.session_idle_minutes', $this->idleSeconds);
    }

    public function sessionAbsoluteSeconds(): int
    {
        $hours = $this->int('security.session_absolute_hours');
        return $hours !== null ? max(1, $hours) * 3600 : $this->absoluteSeconds;
    }

    public function reauthWindowSeconds(): int
    {
        return $this->minutes('security.reauth_minutes', $this->reauthSeconds);
    }

    public function loginMaxAttempts(): int
    {
        return max(3, $this->int('security.login_max_attempts') ?? $this->maxAttempts);
    }

    public function lockoutSeconds(): int
    {
        return $this->minutes('security.lockout_minutes', $this->lockoutSeconds);
    }

    private function minutes(string $key, int $fallbackSeconds): int
    {
        $m = $this->int($key);
        return $m !== null ? max(1, $m) * 60 : $fallbackSeconds;
    }

    private function int(string $key): ?int
    {
        try {
            $this->settings ??= $this->container->get(SettingsService::class);
            $v = $this->settings->get($key);
            return is_numeric($v) ? (int) $v : null;
        } catch (Throwable) {
            return null; // settings not available (install / migrations / unknown key)
        }
    }
}
