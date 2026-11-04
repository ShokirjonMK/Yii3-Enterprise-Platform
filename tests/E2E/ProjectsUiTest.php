<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;

/**
 * Projects module UI flows through nginx: Admin (HTMX list, forms with validation, lifecycle, archive,
 * report) and Web (end-user list/create/view, permission checks).
 */
final class ProjectsUiTest extends TestCase
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    public function testAdminProjectLifecycle(): void
    {
        [$web] = AdminSession::login(['business_admin']);
        $r = $web->get('/admin/projects');
        self::assertSame(200, $r->getStatusCode(), substr($web->body(), 0, 500));
        self::assertStringContainsString('Generate report', $web->body());

        // validation errors are re-rendered with 422
        $web->get('/admin/projects/create');
        $r = $web->post('/admin/projects/create', ['code' => '!', 'name' => '', 'starts_on' => '2026-05-10', 'due_on' => '2026-05-01']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('field__error', $web->body());
        self::assertStringContainsString('aria-invalid="true"', $web->body());

        $code = 'E2E-' . strtoupper(bin2hex(random_bytes(3)));
        $r = $web->post('/admin/projects/create', ['code' => $code, 'name' => 'E2E admin project', 'priority' => 'high', 'tags' => 'e2e, ui', 'budget' => '99.90']);
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('#^/admin/projects/' . self::UUID . '$#', $web->url());
        $viewUrl = $web->url();
        self::assertStringContainsString($code, $web->body());
        self::assertStringContainsString('Project created.', $web->body());

        // edit (optimistic version travels in a hidden field)
        $web->get($viewUrl . '/edit');
        self::assertStringContainsString('name="version" value="1"', $web->body());
        $web->post($viewUrl . '/edit', ['code' => $code, 'name' => 'E2E renamed', 'priority' => 'high', 'version' => '1']);
        self::assertSame($viewUrl, $web->url());
        self::assertStringContainsString('E2E renamed', $web->body());
        // stale form submit → conflict flash, nothing overwritten
        $web->get($viewUrl . '/edit');
        $web->post($viewUrl . '/edit', ['code' => $code, 'name' => 'Stale write', 'version' => '1']);
        self::assertStringContainsString('modified by someone else', $web->body());

        // lifecycle: approve & activate
        $web->get($viewUrl);
        $web->post($viewUrl . '/status', ['status' => 'active', 'version' => '2']);
        self::assertStringContainsString('Status changed to Active', $web->body());
        self::assertStringContainsString('Activity', $web->body());

        // HTMX list request returns only the main region; search finds the project
        $r = $web->htmx('GET', '/admin/projects?q=' . urlencode($code));
        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('<main id="main"', ltrim($web->body()));
        self::assertStringNotContainsString('<html', $web->body());
        self::assertStringContainsString($code, $web->body());

        // HTMX navigation redirects use HX-Location
        $web->get('/admin/projects/create');
        $r = $web->htmx('POST', '/admin/projects/create', ['_csrf' => $web->csrf(), 'code' => $code . 'X', 'name' => 'Via HTMX']);
        self::assertSame(200, $r->getStatusCode());
        $loc = json_decode($r->getHeaderLine('HX-Location'), true);
        self::assertMatchesRegularExpression('#^/admin/projects/' . self::UUID . '$#', (string) ($loc['path'] ?? ''));

        // archive a draft (confirm dialog is client-side; server enforces the rules)
        $web->get((string) $loc['path']);
        self::assertStringContainsString('data-confirm=', $web->body());
        $web->post($loc['path'] . '/archive', ['version' => '1']);
        self::assertStringContainsString('Project archived.', $web->body());

        // report job is enqueued and its status page is reachable
        $web->get('/admin/projects');
        $web->post('/admin/projects/reports', []);
        self::assertMatchesRegularExpression('#^/admin/projects/reports/' . self::UUID . '$#', $web->url());
        self::assertStringContainsString('Projects report', $web->body());
    }

    public function testAdminForbiddenWithoutPermission(): void
    {
        // auditor inherits role "user" (projects.view) but holds no write permission
        [$web] = AdminSession::login(['auditor']);
        self::assertSame(200, $web->get('/admin/projects')->getStatusCode());
        self::assertStringNotContainsString('New project', $web->body());
        self::assertSame(403, $web->get('/admin/projects/create')->getStatusCode());
        $web->get('/admin/projects');
        self::assertSame(403, $web->post('/admin/projects/create', ['code' => 'NOPE-1', 'name' => 'Nope'])->getStatusCode());
    }

    public function testWebUserFlows(): void
    {
        $manager = Fixtures::user(['business_admin']);
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $manager['username'], 'password' => $manager['password']]);

        $r = $web->get('/projects');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('My projects', $web->body());
        $web->get('/projects/new');
        $r = $web->post('/projects/new', ['code' => '', 'name' => '']);
        self::assertSame(422, $r->getStatusCode());
        $code = 'WEB-' . strtoupper(bin2hex(random_bytes(3)));
        $web->post('/projects/new', ['code' => $code, 'name' => 'Web project']);
        self::assertMatchesRegularExpression('#^/projects/' . self::UUID . '$#', $web->url());
        self::assertStringContainsString('Web project', $web->body());
        $projectUrl = $web->url();

        $r = $web->htmx('GET', '/projects?scope=mine&q=' . urlencode($code));
        self::assertStringStartsWith('<main id="main"', ltrim($web->body()));
        self::assertStringContainsString($code, $web->body());

        // a regular user can view tenant projects but not create
        $user = Fixtures::user(['user']);
        $u = new WebClient();
        $u->get('/login');
        $u->post('/login', ['login' => $user['username'], 'password' => $user['password']]);
        self::assertSame(200, $u->get('/projects?scope=all')->getStatusCode());
        self::assertStringContainsString($code, $u->body());
        self::assertSame(200, $u->get($projectUrl)->getStatusCode());
        self::assertStringNotContainsString('/edit"', $u->body(), 'no edit action for non-owners');
        self::assertSame(403, $u->get('/projects/new')->getStatusCode());
        self::assertSame(404, $u->get('/projects/00000000-0000-4000-8000-000000000000')->getStatusCode());
    }
}
