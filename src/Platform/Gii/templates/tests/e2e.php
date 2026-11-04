<?php

declare(strict_types=1);

/**
 * Template: E2E UI test (tests/E2E/<Module>UiTest.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$status = $m->statusField();
$form = [];
foreach ($m->editable() as $f) {
    $s = FieldCode::sample($f);
    if ($s === null) {
        continue;
    }
    if ($f->type === 'bool') {
        $s = $s === 'true' ? "'1'" : "'0'";
    } elseif ($f->type === 'int') {
        $s = '(string) (' . $s . ')';
    } elseif ($f->type === 'datetime') {
        $s = "'2026-12-31T10:30'";
    }
    $form[] = '            ' . $q($f->name) . ' => ' . $s . ',';
}
$required = array_values(array_filter($m->editable(), static fn($f) => $f->required && $f->type !== 'user'));
$display = $m->displayField();
$t = [];
if ($m->has('admin')) {
    $invalidPost = $required !== []
        ? "\n        // validation errors are re-rendered with 422\n        \$web->get('/admin/{{slug}}/create');\n        \$r = \$web->post('/admin/{{slug}}/create', []);\n        self::assertSame(422, \$r->getStatusCode());\n        self::assertStringContainsString('field__error', \$web->body());\n        self::assertStringContainsString('aria-invalid=\"true\"', \$web->body());\n"
        : '';
    $statusFlow = $status !== null
        ? "\n        // lifecycle transition (current version read from the page)\n        \$web->get(\$viewUrl);\n        preg_match('/name=\"version\" value=\"(\\\\d+)\"/', \$web->body(), \$mm);\n        \$web->post(\$viewUrl . '/status', ['status' => '{$status->options[1]}', 'version' => \$mm[1] ?? '1']);\n        self::assertStringContainsString('Status changed to', \$web->body());\n"
        : '';
    $search = $display !== null && $display->isSearchable()
        ? "\n        // HTMX list request returns only the main region and finds the record\n        \$r = \$web->htmx('GET', '/admin/{{slug}}?q=' . urlencode((string) \$edited[" . $q($display->name) . "]));\n        self::assertSame(200, \$r->getStatusCode());\n        self::assertStringStartsWith('<main id=\"main\"', ltrim(\$web->body()));\n        self::assertStringNotContainsString('<html', \$web->body());\n        self::assertStringContainsString(htmlspecialchars((string) \$edited[" . $q($display->name) . "], ENT_QUOTES), \$web->body());\n"
        : "\n        \$r = \$web->htmx('GET', '/admin/{{slug}}');\n        self::assertStringStartsWith('<main id=\"main\"', ltrim(\$web->body()));\n";
    $editLine = $display !== null
        ? "\n        \$edited[" . $q($display->name) . "] = mb_substr('Edited ' . bin2hex(random_bytes(3)), 0, " . (int) $display->maxLength() . ');'
        : '';
    $t[] = <<<PHP
    public function testAdminCrudFlow(): void
    {
        [\$web] = AdminSession::login(['business_admin']);
        \$r = \$web->get('/admin/{{slug}}');
        self::assertSame(200, \$r->getStatusCode(), substr(\$web->body(), 0, 500));
        self::assertStringContainsString('{{label}}', \$web->body());
$invalidPost
        \$form = \$this->form();
        \$web->get('/admin/{{slug}}/create');
        \$r = \$web->post('/admin/{{slug}}/create', \$form);
        self::assertSame(200, \$r->getStatusCode(), substr(\$web->body(), 0, 800));
        self::assertMatchesRegularExpression('#^/admin/{{slug}}/' . self::UUID . '\$#', \$web->url());
        self::assertStringContainsString('{{entityLabel}} created.', \$web->body());
        \$viewUrl = \$web->url();

        // edit: the optimistic version travels in a hidden field; a stale submit is refused
        \$web->get(\$viewUrl . '/edit');
        self::assertStringContainsString('name="version" value="1"', \$web->body());
        \$edited = \$form;$editLine
        \$web->post(\$viewUrl . '/edit', \$edited + ['version' => '1']);
        self::assertSame(\$viewUrl, \$web->url());
        \$web->get(\$viewUrl . '/edit');
        \$web->post(\$viewUrl . '/edit', ['version' => '0'] + \$form);
        self::assertStringContainsString('modified by someone else', \$web->body());
$search$statusFlow
        // delete (confirm dialog is client-side; the server enforces the permission)
        \$web->get(\$viewUrl);
        self::assertStringContainsString('data-confirm=', \$web->body());
        \$web->post(\$viewUrl . '/delete', []);
        self::assertStringContainsString('{{entityLabel}} deleted.', \$web->body());
        self::assertSame(404, \$web->get(\$viewUrl)->getStatusCode());
    }

    public function testAdminForbiddenWithoutPermission(): void
    {
        // auditor inherits "user" ({{code}}.view) but holds no write permission
        [\$web] = AdminSession::login(['auditor']);
        self::assertSame(200, \$web->get('/admin/{{slug}}')->getStatusCode());
        self::assertSame(403, \$web->get('/admin/{{slug}}/create')->getStatusCode());
        self::assertSame(404, \$web->get('/admin/{{slug}}/00000000-0000-4000-8000-000000000000')->getStatusCode());
    }
PHP;
}
if ($m->has('web')) {
    $t[] = <<<'PHP'
    public function testWebUserFlows(): void
    {
        $manager = Fixtures::user(['business_admin']);
        $web = new WebClient();
        $web->get('/login');
        $web->post('/login', ['login' => $manager['username'], 'password' => $manager['password']]);
        self::assertSame(200, $web->get('/{{slug}}')->getStatusCode(), substr($web->body(), 0, 500));
        $web->get('/{{slug}}/new');
        $web->post('/{{slug}}/new', $this->form());
        self::assertMatchesRegularExpression('#^/{{slug}}/' . self::UUID . '$#', $web->url(), substr($web->body(), 0, 800));
        $url = $web->url();

        // a regular user can view but not create
        $user = Fixtures::user(['user']);
        $u = new WebClient();
        $u->get('/login');
        $u->post('/login', ['login' => $user['username'], 'password' => $user['password']]);
        self::assertSame(200, $u->get('/{{slug}}')->getStatusCode());
        self::assertSame(200, $u->get($url)->getStatusCode());
        self::assertStringNotContainsString('/edit"', $u->body());
        self::assertSame(403, $u->get('/{{slug}}/new')->getStatusCode());
    }
PHP;
}

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Platform\Settings\ConfigRevision;
use App\Tests\Support\AdminSession;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;
use App\Tests\Support\WebClient;
use PHPUnit\Framework\TestCase;

/**
 * {{label}} UI flows through nginx: Admin (HTMX list, validation, edit with optimistic locking, delete) and
 * Web (end-user list/create/view, permission checks).
 */
final class {{Module}}UiTest extends TestCase
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    private static bool $revisionBumped = false;

    protected function setUp(): void
    {
        Fixtures::resetLoginThrottle();
        if (!self::$revisionBumped) {
            TestContainer::service(ConfigRevision::class)->bump();
            self::$revisionBumped = true;
        }
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

__TESTS__

    /** @return array<string,string> */
    private function form(): array
    {
        return [
__FORM__
        ];
    }
}

PHP, ['__TESTS__' => implode("\n\n", $t), '__FORM__' => implode("\n", $form)]);
