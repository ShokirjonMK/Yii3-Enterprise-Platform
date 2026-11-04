<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Logs a fresh user with the given roles into the admin panel (MFA enrolled) and returns the client.
 *
 *   [$web, $user] = AdminSession::login(['platform_admin']);
 *   $web->get('/admin/users');
 */
final class AdminSession
{
    /**
     * @param list<string> $roles
     * @return array{0: WebClient, 1: array{id:string, username:string, password:string, mfa_secret:?string}}
     */
    public static function login(array $roles = ['superadmin']): array
    {
        $user = Fixtures::user($roles, mfa: true);
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $user['username'], 'password' => $user['password']]);
        $web->post('/login/mfa', ['code' => Fixtures::totp((string) $user['mfa_secret'])]);
        if (!str_starts_with($web->url(), '/admin') && !str_starts_with($web->url(), '/')) {
            throw new \RuntimeException('Admin login failed, landed on ' . $web->url());
        }
        return [$web, $user];
    }

    /**
     * Step-up re-authentication (for routes protected by RequireRecentAuth).
     */
    public static function reauth(WebClient $web, array $user): void
    {
        $web->get('/auth/confirm?return=/admin');
        // The previous TOTP step is already consumed; use the next one
        $web->post('/auth/confirm', ['password' => $user['password'], 'code' => Fixtures::totp((string) $user['mfa_secret'], 1), 'return' => '/admin']);
    }
}
