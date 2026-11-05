<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;

/**
 * Admin → System Settings → Appearance: gallery, preview, activation (permission + step-up), theme CSS served,
 * personal theme in Account → Appearance.
 *
 * Author: ShokirjonMK
 */
final class AppearanceTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        [$web, $user] = AdminSession::login(['superadmin']);
        AdminSession::reauth($web, $user);
        $web->post('/admin/settings/appearance/activate', ['theme' => 'default']);
        $web->post('/admin/settings/appearance/user-choice', ['allow' => '1']);
        Fixtures::cleanup();
    }

    public function testGalleryPreviewAndActivate(): void
    {
        [$web, $user] = AdminSession::login(['superadmin']);
        self::assertSame(200, $web->get('/admin/settings/appearance')->getStatusCode());
        foreach (['Default', 'Ocean Corporate', 'Emerald', 'Sunset', 'Graphite', 'High Contrast', 'Gov UZ', 'Download the starter template'] as $text) {
            self::assertStringContainsString($text, $web->body());
        }

        // preview: only this session gets the stylesheet
        $web->post('/admin/settings/appearance/graphite/preview');
        $web->get('/admin/settings/appearance');
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/appearance/graphite\.css\?v=[0-9a-f]{16}">#', $web->body());
        self::assertStringContainsString('data-theme-pack="graphite"', $web->body());
        $guest = new WebClient();
        $guest->get('/login');
        self::assertStringNotContainsString('graphite.css', $guest->body());
        $web->post('/admin/settings/appearance/preview/stop');

        // activation (route: settings.manage + RequireRecentAuth; the fresh MFA sign-in counts as recent)
        AdminSession::reauth($web, $user);
        $web->post('/admin/settings/appearance/activate', ['theme' => 'ocean']);
        self::assertSame('/admin/settings/appearance', $web->url());
        self::assertStringContainsString('is now active for everyone', $web->body());
        $guest = new WebClient();
        $guest->get('/login');
        self::assertMatchesRegularExpression('#href="/appearance/ocean\.css\?v=([0-9a-f]{16})"#', $guest->body(), 'everyone gets the active theme');
        preg_match('#/appearance/ocean\.css\?v=([0-9a-f]{16})#', $guest->body(), $m);
        $css = $guest->get('/appearance/ocean.css?v=' . $m[1]);
        self::assertSame(200, $css->getStatusCode());
        self::assertStringContainsString('text/css', $css->getHeaderLine('Content-Type'));
        self::assertStringContainsString('immutable', $css->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('--primary: #0b6bcb', (string) $css->getBody());

        // starter template download
        $zip = $web->get('/admin/settings/appearance/default/download?starter=1');
        self::assertSame('application/zip', $zip->getHeaderLine('Content-Type'));
        self::assertStringStartsWith("PK", (string) $zip->getBody());
    }

    public function testReadOnlyRoleCannotActivate(): void
    {
        [$web] = AdminSession::login(['auditor']);
        $r = $web->get('/admin/settings/appearance');
        self::assertSame(200, $r->getStatusCode(), 'auditors may view the gallery');
        self::assertStringNotContainsString('/admin/settings/appearance/activate', $web->body());
        $r = $web->post('/admin/settings/appearance/activate', ['theme' => 'sunset']);
        self::assertSame(403, $r->getStatusCode());
    }

    public function testUserPicksPersonalTheme(): void
    {
        [$web] = AdminSession::login(['user']);
        self::assertSame(200, $web->get('/account/appearance')->getStatusCode());
        $web->post('/account/appearance', ['theme' => 'emerald']);
        $web->get('/account/appearance');
        self::assertStringContainsString('emerald.css', $web->body());
        $web->post('/account/appearance', ['theme' => '']);
        $web->get('/account/appearance');
        self::assertStringNotContainsString('emerald.css', $web->body());
    }
}
