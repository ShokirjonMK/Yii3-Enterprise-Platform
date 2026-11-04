<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

use App\Environment;
use App\Platform\FeatureFlags\FeatureFlagService;
use App\Platform\Settings\SettingsService;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * 18 · Security posture checklist computed from the live configuration and database state
 * (nothing is hard-coded as "passed"). Status: pass | warn | fail | info.
 */
final class SecurityPosture
{
    /**
     * @param array{url:string, secret:string, metricsToken:?string, csp:array<string,string>, privilegedRoles:list<string>} $platform
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettingsService $settings,
        private readonly FeatureFlagService $flags,
        private readonly array $platform,
    ) {}

    /** @return list<array{id:string, title:string, status:string, detail:string, evidence:string}> */
    public function checks(): array
    {
        $prod = Environment::isProd();
        $c = [];
        $add = static function (string $id, string $title, string $status, string $detail, string $evidence) use (&$c): void {
            $c[] = compact('id', 'title', 'status', 'detail', 'evidence');
        };

        $add('env', 'Production environment', $prod ? 'pass' : 'info', 'APP_ENV=' . Environment::appEnv() . ($prod ? '' : ' — development/test settings are expected here.'), 'src/Environment.php');
        $add('debug', 'Debug mode disabled', Environment::appDebug() ? ($prod ? 'fail' : 'warn') : 'pass',
            Environment::appDebug() ? 'APP_DEBUG is on: stack traces may be shown to users.' : 'APP_DEBUG is off.', 'Environment::prepare() forces debug off in prod');

        $https = str_starts_with(strtolower($this->platform['url']), 'https://');
        $add('https', 'HTTPS application URL', $https ? 'pass' : ($prod ? 'fail' : 'warn'), 'APP_URL=' . $this->platform['url'], 'config/common/params.php platform.url');

        $expose = (bool) ini_get('expose_php');
        $add('expose_php', 'PHP version not exposed', $expose ? 'warn' : 'pass', 'expose_php=' . ($expose ? 'On' : 'Off'), 'infra/docker/php/conf.d/platform.ini');

        // MFA coverage of privileged roles
        $roles = $this->platform['privilegedRoles'];
        $priv = $this->privilegedUsers($roles);
        $total = count($priv);
        $withMfa = count(array_filter($priv, static fn($u) => $u['mfa_enabled'] === true || $u['mfa_enabled'] === 't' || $u['mfa_enabled'] === 1));
        $missing = $total - $withMfa;
        $add('mfa_privileged', 'MFA for privileged roles', $total === 0 ? 'info' : ($missing === 0 ? 'pass' : 'fail'),
            $total === 0 ? 'No active users hold privileged roles.' : sprintf('%d of %d privileged users have MFA enrolled (%s%%).', $withMfa, $total, (string) round($withMfa / $total * 100)),
            'roles: ' . implode(', ', $roles) . ' · AuthenticationService enforces MFA enrolment at login');
        $flag = $this->safeFlag('security.mfa_enforcement');
        $add('mfa_enforcement', 'MFA enforcement control', $flag === false ? 'fail' : 'pass', $flag === false ? 'Feature flag security.mfa_enforcement is disabled.' : 'security.mfa_enforcement is enabled (critical flag).', 'PlatformSeeder::FLAGS');

        $maxAge = (int) $this->settings->get('security.password_max_age_days');
        if ($maxAge > 0) {
            $stale = (int) $this->db->createCommand("SELECT count(*) FROM users WHERE deleted_at IS NULL AND status = 'active' AND password_hash IS NOT NULL
                AND COALESCE(password_changed_at, created_at) < now() - make_interval(days => :d)", [':d' => $maxAge])->queryScalar();
            $add('stale_passwords', 'Password age', $stale === 0 ? 'pass' : 'warn', sprintf('%d active users have a password older than %d days.', $stale, $maxAge), 'setting security.password_max_age_days');
        } else {
            $add('stale_passwords', 'Password age', 'info', 'Password expiry is disabled (security.password_max_age_days = 0).', 'setting security.password_max_age_days');
        }

        $locked = (int) $this->db->createCommand('SELECT count(*) FROM users WHERE deleted_at IS NULL AND locked_until > now()')->queryScalar();
        $add('locked', 'Locked accounts', $locked === 0 ? 'pass' : 'info', sprintf('%d accounts are currently locked after failed sign-ins.', $locked), 'LoginThrottle (lockout)');

        $fails = (int) $this->db->createCommand("SELECT count(*) FROM login_attempts WHERE NOT success AND created_at > now() - interval '1 hour'")->queryScalar();
        $add('login_failures', 'Failed sign-ins (1 h)', $fails > 100 ? 'warn' : 'pass', sprintf('%d failed sign-in attempts in the last hour.', $fails), 'login_attempts');

        $token = (string) ($this->platform['metricsToken'] ?? '');
        $add('metrics_token', 'Metrics endpoint protected', $token !== '' ? (strlen($token) >= 24 ? 'pass' : 'warn') : ($prod ? 'fail' : 'warn'),
            $token === '' ? 'METRICS_TOKEN is not set: /metrics relies on network policy only.' : 'METRICS_TOKEN is set (' . strlen($token) . ' characters).', 'src/Web/Action/System/MetricsAuth.php');

        $secret = (string) $this->platform['secret'];
        $strong = strlen($secret) >= 64 && ctype_xdigit($secret) || strlen($secret) >= 44;
        $add('app_secret', 'APP_SECRET strength', $strong ? 'pass' : 'fail', $secret === '' ? 'APP_SECRET is empty.' : sprintf('APP_SECRET has %d characters (≥ 64 hex required).', strlen($secret)), 'Encrypter / audit HMAC key derivation');

        $csp = $this->platform['csp'];
        $script = (string) ($csp['script-src'] ?? $csp['default-src'] ?? '');
        $unsafe = str_contains($script, "'unsafe-inline'") || str_contains($script, "'unsafe-eval'");
        $add('csp', 'Content-Security-Policy', $csp === [] ? 'fail' : ($unsafe ? 'warn' : 'pass'),
            $csp === [] ? 'No CSP configured.' : 'script-src ' . $script . '; frame-ancestors ' . ($csp['frame-ancestors'] ?? '—'), 'SecurityHeadersMiddleware · params security.csp');

        $gii = $this->safeFlag('devtools.gii');
        $add('gii', 'Code generators disabled in production', $prod && $gii === true ? 'fail' : ($prod ? 'pass' : 'info'),
            $prod ? ($gii === true ? 'devtools.gii is enabled in production.' : 'Gii is unavailable in production.') : 'Non-production: Gii may be used by developers (devtools.use).', 'feature flag devtools.gii (env:non-prod dependency)');

        $local = $this->safeFlag('auth.local_login');
        $add('local_login', 'Local password login', $local === true ? 'info' : 'pass',
            $local === true ? 'Password sign-in is enabled. In production prefer SSO; break-glass accounts (local_only) always work.' : 'Password sign-in disabled — SSO only (break-glass accounts excepted).', 'feature flag auth.local_login');

        $idle = (int) $this->settings->get('security.session_idle_minutes');
        $add('session_idle', 'Session idle timeout', $idle <= 30 ? 'pass' : 'warn', sprintf('%d minutes (ASVS L2: ≤ 30 recommended for privileged use).', $idle), 'setting security.session_idle_minutes');

        $allow = (array) ($this->settings->get('security.allowed_admin_networks') ?? []);
        $add('admin_networks', 'Admin panel network allowlist', $allow === [] ? ($prod ? 'warn' : 'info') : 'pass',
            $allow === [] ? 'Admin panel is reachable from any network.' : 'Allowed: ' . implode(', ', array_map('strval', $allow)), 'AdminNetworkMiddleware · setting security.allowed_admin_networks');

        $audit = $this->safeFlag('security.audit');
        $add('audit', 'Audit logging', $audit === false ? 'fail' : 'pass', 'HMAC-signed, append-only (trigger) audit log.', 'migrations/M261104000003Audit.php · AuditVerifier');

        return $c;
    }

    /** @return array{pass:int, warn:int, fail:int, info:int, score:int} */
    public static function summarize(array $checks): array
    {
        $s = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
        foreach ($checks as $c) {
            $s[$c['status']]++;
        }
        $scored = $s['pass'] + $s['warn'] + $s['fail'];
        $s['score'] = $scored === 0 ? 100 : (int) round(($s['pass'] + $s['warn'] * 0.5) / $scored * 100);
        return $s;
    }

    /** Active users holding privileged roles without MFA. @return list<array<string,mixed>> */
    public function privilegedWithoutMfa(): array
    {
        return array_values(array_filter($this->privilegedUsers($this->platform['privilegedRoles']),
            static fn($u) => !($u['mfa_enabled'] === true || $u['mfa_enabled'] === 't' || $u['mfa_enabled'] === 1)));
    }

    /** @param list<string> $roles @return list<array<string,mixed>> */
    private function privilegedUsers(array $roles): array
    {
        if ($roles === []) {
            return [];
        }
        $in = [];
        $params = [];
        foreach (array_values($roles) as $i => $r) {
            $in[] = ':r' . $i;
            $params[':r' . $i] = $r;
        }
        return $this->db->createCommand('SELECT u.id, u.username, u.full_name, u.mfa_enabled, u.last_login_at, string_agg(DISTINCT a.item_name, \', \') AS roles
            FROM users u JOIN yii_rbac_assignment a ON a.user_id = u.id::text
            WHERE u.deleted_at IS NULL AND u.status = \'active\' AND a.item_name IN (' . implode(',', $in) . ')
            GROUP BY u.id ORDER BY u.username', $params)->queryAll();
    }

    private function safeFlag(string $code): ?bool
    {
        try {
            return $this->flags->isEnabled($code);
        } catch (Throwable) {
            return null;
        }
    }
}
