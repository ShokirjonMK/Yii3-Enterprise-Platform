<?php

declare(strict_types=1);

namespace App\Tests\E2E\Admin;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/** 23 · Business Modules: registry from manifests, toggle through the module flag. */
final class ModulesAdminTest extends TestCase
{
    private ?array $before = null;

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
        $db = Fixtures::db();
        $this->before = [
            'flag' => $db->createCommand("SELECT enabled, status FROM feature_flags WHERE code = 'module.projects'")->queryOne() ?: null,
            'module' => $db->createCommand("SELECT enabled FROM business_modules WHERE code = 'projects'")->queryOne() ?: null,
        ];
    }

    protected function tearDown(): void
    {
        $db = Fixtures::db();
        if ($this->before['flag'] !== null) {
            $db->createCommand("UPDATE feature_flags SET enabled = :e, status = :s WHERE code = 'module.projects'", [':e' => $this->before['flag']['enabled'], ':s' => $this->before['flag']['status']])->execute();
        }
        if ($this->before['module'] === null) {
            $db->createCommand("DELETE FROM business_modules WHERE code = 'projects'")->execute();
        } else {
            $db->createCommand("UPDATE business_modules SET enabled = :e WHERE code = 'projects'", [':e' => $this->before['module']['enabled']])->execute();
        }
        $db->createCommand('UPDATE config_revision SET revision = revision + 1')->execute();
        Fixtures::cleanup();
    }

    public function testRegistryAndToggle(): void
    {
        [$web] = AdminSession::login(['platform_admin']);
        $r = $web->get('/admin/modules');
        self::assertSame(200, $r->getStatusCode(), substr($web->body(), 0, 300));
        self::assertStringContainsString('data-module="projects"', $web->body());

        $web->post('/admin/modules/sync');
        self::assertStringContainsString('Registry synchronised', $web->body());

        $web->get('/admin/modules/projects');
        $web->post('/admin/modules/projects/toggle', ['enabled' => '0']);
        self::assertStringContainsString('Module disabled.', $web->body());
        self::assertStringContainsString('data-module-state="off"', $web->body());
        $web->post('/admin/modules/projects/toggle', ['enabled' => '1']);
        self::assertStringContainsString('Module enabled.', $web->body());
        self::assertStringContainsString('data-module-state="on"', $web->body());
        self::assertTrue((bool) Fixtures::db()->createCommand("SELECT enabled FROM business_modules WHERE code = 'projects'")->queryScalar());
    }

    public function testManageRequiresPermission(): void
    {
        [$web] = AdminSession::login(['developer']);
        self::assertSame(200, $web->get('/admin/modules')->getStatusCode());
        self::assertSame(403, $web->post('/admin/modules/projects/toggle', ['enabled' => '0'])->getStatusCode());
    }
}
