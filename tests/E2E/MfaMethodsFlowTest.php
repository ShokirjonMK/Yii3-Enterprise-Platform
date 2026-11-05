<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end two-factor against the running stack: e-mail enrolment and sign-in with a real code delivered through
 * SMTP (Mailpit), "trust this device", step-up with the e-mail code, admin Two-factor pages, policy enforcement and reset.
 */
final class MfaMethodsFlowTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::db()->createCommand()->delete('mfa_policies', ['subject' => 'auditor'])->execute();
        Fixtures::cleanup();
    }

    public function testEmailEnrolmentSignInTrustedDeviceAndStepUp(): void
    {
        $u = Fixtures::user(['user']);
        $mail = 'mfa-' . bin2hex(random_bytes(4)) . '@example.test';
        $web = $this->login($u);
        self::assertSame('/', parse_url($web->url(), PHP_URL_PATH) ?: '/');

        $web->get('/auth/mfa/setup?method=email');
        self::assertStringContainsString('name="target"', $web->body());
        $web->post('/auth/mfa/setup/code', ['method' => 'email', 'target' => $mail]);
        $code = $this->mailCode($mail);
        $web->post('/auth/mfa/setup/code/verify', ['code' => $code]);
        self::assertStringContainsString('recovery codes', $web->body(), 'first factor shows recovery codes');

        // sign in again: second step offers the e-mail method
        $web->post('/logout');
        $web = $this->login($u);
        self::assertStringStartsWith('/login/mfa', $web->url());
        self::assertStringContainsString('Send code', $web->body());
        $web->post('/login/mfa/send', ['method' => 'email']);
        $r = $web->post('/login/mfa', ['method' => 'email', 'code' => '00000000']);
        self::assertSame(422, $r->getStatusCode());
        $web->post('/login/mfa', ['method' => 'email', 'code' => $this->mailCode($mail), 'remember' => '1']);
        self::assertStringNotContainsString('/login', $web->url());

        // remembered device: the next sign-in on this browser skips the second step (default policy: 30 days)
        $web->post('/logout');
        $web->get('/login');
        $web->post('/login', ['login' => $u['username'], 'password' => $u['password']]);
        self::assertStringNotContainsString('/login/mfa', $web->url(), 'trusted device skips 2FA');

        // step-up: password, then the e-mail code
        $web->post('/auth/confirm', ['password' => $u['password'], 'return' => '/account/security']);
        self::assertStringStartsWith('/auth/confirm', $web->url());
        $web->post('/auth/confirm/send', ['method' => 'email', 'return' => '/account/security']);
        $web->post('/auth/confirm', ['method' => 'email', 'code' => $this->mailCode($mail), 'return' => '/account/security']);
        self::assertSame('/account/security', $web->url());
        self::assertStringContainsString('Remembered devices', $web->body());
    }

    public function testAdminTwoFactorPagesPolicyEnforcementAndReset(): void
    {
        [$admin, $adminUser] = AdminSession::login(['superadmin']);
        $r = $admin->get('/admin/auth/mfa');
        self::assertSame(200, $r->getStatusCode());
        foreach (['Authenticator app', 'Passkey', 'Telegram', 'E-IMZO', 'Voice call', 'Policies by role'] as $text) {
            self::assertStringContainsString($text, $admin->body());
        }
        self::assertSame(200, $admin->get('/admin/auth/mfa/settings')->getStatusCode());
        self::assertStringContainsString('E-IMZO-SERVER URL', $admin->body());

        AdminSession::reauth($admin, $adminUser);
        $admin->post('/admin/auth/mfa/policies', ['subject' => 'auditor', 'required' => '1', 'allowed' => ['totp', 'email'], 'remember_days' => '0']);
        $row = Fixtures::db()->createCommand("SELECT required FROM mfa_policies WHERE subject = 'auditor'")->queryScalar();
        self::assertTrue((bool) $row, 'policy stored');

        // an auditor without 2FA is now forced to enrol, and only allowed methods are offered
        $aud = Fixtures::user(['auditor']);
        $web = $this->login($aud);
        self::assertSame('/auth/mfa/setup', $web->url());
        self::assertStringContainsString('Manual key', $web->body());
        self::assertStringNotContainsString('?method=telegram', $web->body());
        preg_match('/Manual key[^<]*<code>([A-Z2-7 ]+)<\/code>/', $web->body(), $m);
        $web->post('/auth/mfa/setup', ['code' => Fixtures::totp(str_replace(' ', '', (string) ($m[1] ?? '')))]);
        $web->get('/admin');
        self::assertSame('/admin', $web->url());

        // admin resets the auditor's 2FA
        $admin->post('/admin/users/' . $aud['id'] . '/mfa-reset');
        self::assertFalse((bool) Fixtures::db()->createCommand('SELECT mfa_enabled FROM users WHERE id = :id', [':id' => $aud['id']])->queryScalar());

        // a lower role cannot open the page
        [$user] = AdminSession::login(['user']);
        self::assertSame(403, $user->get('/admin/auth/mfa')->getStatusCode());
    }

    public function testTelegramWebhookRejectsWrongSecret(): void
    {
        $web = new WebClient();
        $r = $web->post('/integrations/telegram/webhook', [], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong']);
        self::assertSame(403, $r->getStatusCode());
    }

    private function login(array $u): WebClient
    {
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $u['username'], 'password' => $u['password']]);
        return $web;
    }

    /** Latest code e-mailed to $to (Mailpit HTTP API). */
    private function mailCode(string $to): string
    {
        $base = getenv('MAILPIT_URL') ?: 'http://mailpit:8025';
        for ($i = 0; $i < 20; $i++) {
            $list = json_decode((string) @file_get_contents($base . '/api/v1/search?limit=1&query=' . rawurlencode('to:"' . $to . '"')), true);
            $id = $list['messages'][0]['ID'] ?? null;
            if (is_string($id)) {
                $msg = json_decode((string) file_get_contents($base . '/api/v1/message/' . $id), true);
                if (preg_match('/(\d{6,8})/', (string) ($msg['Subject'] ?? ''), $m)) {
                    // consume: delete so the next call waits for a fresh code
                    $ctx = stream_context_create(['http' => ['method' => 'DELETE', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode(['IDs' => [$id]])]]);
                    @file_get_contents($base . '/api/v1/messages', false, $ctx);
                    return $m[1];
                }
            }
            usleep(250000);
        }
        self::fail("No code e-mail for $to");
    }
}
