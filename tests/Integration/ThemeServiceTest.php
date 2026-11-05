<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Appearance\ThemeRegistry;
use App\Platform\Appearance\ThemeService;
use App\Platform\Appearance\ThemeValidator;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fixtures;
use ZipArchive;

/**
 * Design themes: built-in packs, validation/sanitizing of uploaded packs, activation and resolution order.
 *
 * Author: ShokirjonMK
 */
final class ThemeServiceTest extends IntegrationTestCase
{
    private ThemeService $themes;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->themes = $this->get(ThemeService::class);
        $this->db->createCommand()->delete('appearance_themes', ['like', 'code', 'test-%', false])->execute();
        $this->db->createCommand()->delete('appearance_choice', ['scope' => ['user', 'tenant']])->execute();
        $this->db->createCommand()->update('appearance_choice', ['theme' => 'default', 'allow_user_choice' => true], ['scope' => 'global'])->execute();
    }

    protected function tearDown(): void
    {
        $this->db->createCommand()->delete('appearance_themes', ['like', 'code', 'test-%', false])->execute();
        $this->db->createCommand()->delete('appearance_choice', ['scope' => ['user', 'tenant']])->execute();
        $this->db->createCommand()->update('appearance_choice', ['theme' => 'default', 'allow_user_choice' => true], ['scope' => 'global'])->execute();
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function testBuiltInThemesAreValidAndOnlyUseTokens(): void
    {
        $all = $this->themes->registry()->all();
        foreach (['default', 'ocean', 'emerald', 'sunset', 'graphite', 'contrast', 'gov-uz'] as $code) {
            self::assertArrayHasKey($code, $all, "built-in theme $code");
            self::assertTrue($all[$code]['builtin']);
            $css = (string) $this->themes->registry()->css($code);
            (new ThemeValidator())->css($css); // passes the sanitizer
            self::assertStringContainsString('--primary:', $css);
            self::assertStringContainsString(':root[data-theme="dark"]', $css, "$code supports dark mode");
        }
        self::assertSame('default', array_key_first($all));
    }

    public function testSanitizerRejectsDangerousCss(): void
    {
        $v = new ThemeValidator();
        $bad = [
            '@import url("https://evil.example/x.css");',
            ':root{--bg:url(https://evil.example/t.png)}',
            ':root{--bg:url(//evil.example/t.png)}',
            'a{background:url("javascript:alert(1)")}',
            'a{width:expression(alert(1))}',
            '</style><script>alert(1)</script>',
            'a{-moz-binding:url(x.xml#x)}',
            'a{background:url("\6A avascript:alert(1)")}',
            ':root{--x:1',
        ];
        foreach ($bad as $css) {
            try {
                $v->css($css);
                self::fail('Accepted dangerous CSS: ' . $css);
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(':root{--primary:#123456}a{background:url(data:image/png;base64,AAAA)}',
            $v->css(':root{--primary:#123456}a{background:url(data:image/png;base64,AAAA)}'));
    }

    public function testZipValidation(): void
    {
        $v = new ThemeValidator();
        $this->expectRejected($v, $this->zip(['theme.json' => '{"code":"test-a","name":"A"}', 'theme.css' => ':root{}', 'evil.php' => '<?php']));
        $this->expectRejected($v, $this->zip(['../theme.json' => '{}', 'theme.css' => ':root{}']));
        $this->expectRejected($v, $this->zip(['theme.css' => ':root{}']));
        $this->expectRejected($v, $this->zip(['theme.json' => '{"code":"Bad Code","name":"A"}', 'theme.css' => ':root{}']));
        [$m, $css] = $v->zip($this->zip(['test-ok/theme.json' => '{"code":"test-ok","name":"OK","version":"1.2.0","colors":{"primary":"#AA0000"}}', 'test-ok/theme.css' => ':root{--primary:#aa0000}']));
        self::assertSame('test-ok', $m['code']);
        self::assertSame('#aa0000', $m['colors']['primary']);
        self::assertSame(':root{--primary:#aa0000}', $css);
    }

    public function testInstallActivateResolveAndDelete(): void
    {
        $admin = Fixtures::user(['superadmin']);
        $user = Fixtures::user(['user']);
        $m = $this->themes->installZip($admin['id'], $this->zip(['theme.json' => '{"code":"test-brand","name":"Brand"}', 'theme.css' => ':root{--primary:#ff0000}']));
        self::assertSame('test-brand', $m['code']);
        self::assertSame(':root{--primary:#ff0000}', $this->themes->registry()->css('test-brand'));
        self::assertFalse($this->themes->registry()->get('test-brand')['builtin']);

        // same code again needs --replace; built-in codes are protected
        try {
            $this->themes->installZip($admin['id'], $this->zip(['theme.json' => '{"code":"test-brand","name":"Brand 2"}', 'theme.css' => ':root{}']));
            self::fail('duplicate accepted');
        } catch (ConflictException) {
        }
        try {
            $this->themes->installZip($admin['id'], $this->zip(['theme.json' => '{"code":"ocean","name":"Fake"}', 'theme.css' => ':root{}']), true);
            self::fail('built-in overwritten');
        } catch (ConflictException) {
        }

        $this->themes->activate($admin['id'], 'test-brand');
        self::assertSame('test-brand', $this->freshService()->resolve(null, $user['id']));

        // personal choice wins while allowed; ignored when switched off
        $this->themes->chooseForUser($user['id'], 'emerald');
        self::assertSame('emerald', $this->freshService()->resolve(null, $user['id']));
        $this->themes->allowUserChoice($admin['id'], false);
        self::assertSame('test-brand', $this->freshService()->resolve(null, $user['id']));
        try {
            $this->themes->chooseForUser($user['id'], 'sunset');
            self::fail('choice accepted while disallowed');
        } catch (AccessDeniedException) {
        }

        // preview beats everything (admin's own session)
        self::assertSame('graphite', $this->freshService()->resolve(null, $user['id'], 'graphite'));

        // active theme cannot be deleted; after switching it can, and rows pointing to it are cleaned
        try {
            $this->themes->delete($admin['id'], 'test-brand');
            self::fail('active theme deleted');
        } catch (ConflictException) {
        }
        $this->themes->activate($admin['id'], 'ocean');
        $this->themes->delete($admin['id'], 'test-brand');
        self::assertFalse($this->themes->registry()->exists('test-brand'));
        self::assertGreaterThan(0, (int) $this->db->createCommand("SELECT count(*) FROM audit_log WHERE action = 'appearance.theme.installed'")->queryScalar());
    }

    public function testTenantThemeAndPermission(): void
    {
        $admin = Fixtures::user(['superadmin']);
        $plain = Fixtures::user(['user']);
        $tenant = (string) $this->db->createCommand('SELECT id FROM tenants ORDER BY created_at LIMIT 1')->queryScalar();
        $this->themes->activate($admin['id'], 'gov-uz', 'tenant', $tenant);
        self::assertSame('gov-uz', $this->freshService()->resolve($tenant, null));
        self::assertSame('default', $this->freshService()->resolve(null, null));
        $this->themes->activate($admin['id'], null, 'tenant', $tenant);
        self::assertSame('default', $this->freshService()->resolve($tenant, null));

        $this->expectException(AccessDeniedException::class);
        $this->themes->activate($plain['id'], 'ocean');
    }

    public function testExportAndScaffold(): void
    {
        $zip = $this->themes->exportZip('ocean', 'test-starter');
        $f = (string) tempnam(sys_get_temp_dir(), 'tz');
        $this->files[] = $f;
        file_put_contents($f, $zip);
        [$m] = (new ThemeValidator())->zip($f);
        self::assertSame('test-starter', $m['code'], 'starter template round-trips through the validator');

        $dir = $this->themes->scaffold('test-made', 'sunset');
        try {
            $this->themes->registry()->forget();
            self::assertTrue($this->themes->registry()->get('test-made')['builtin']);
            self::assertSame($this->themes->registry()->css('sunset'), $this->themes->registry()->css('test-made'));
        } finally {
            @unlink($dir . '/theme.json');
            @unlink($dir . '/theme.css');
            @rmdir($dir);
        }
    }

    private function freshService(): ThemeService
    {
        $this->get(ThemeRegistry::class)->forget();
        return new ThemeService($this->get(ThemeRegistry::class), new ThemeValidator(), $this->db,
            $this->get(\App\Platform\Access\AccessService::class), $this->get(\App\Platform\Audit\AuditLogger::class));
    }

    private function expectRejected(ThemeValidator $v, string $zip): void
    {
        try {
            $v->zip($zip);
            self::fail('Accepted invalid zip');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'tz');
        $this->files[] = $f;
        $z = new ZipArchive();
        $z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $z->addFromString($name, $content);
        }
        $z->close();
        return $f;
    }
}
