<?php

declare(strict_types=1);

/**
 * Template: REST API test (tests/Api/Api<Module>Test.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$status = $m->statusField();
$valid = [];
foreach ($m->editable() as $f) {
    $s = FieldCode::sample($f);
    if ($s !== null) {
        $valid[] = '            ' . $q($f->name) . ' => ' . $s . ',';
    }
}
$invalid = [];
$invalidKeys = [];
foreach ($m->editable() as $f) {
    $bad = FieldCode::invalid($f);
    if ($bad !== null && $f->type !== 'user') {
        $invalid[] = '            ' . $q($f->name) . ' => ' . $bad . ',';
        $invalidKeys[] = $q($f->name);
    }
}
$updateField = null;
foreach ($m->editable() as $f) {
    if ($f->type !== 'user') {
        $updateField = $f;
        break;
    }
}
$t = [];
if ($updateField !== null) {
    $k = $q($updateField->name);
    $v = FieldCode::sample($updateField, 1);
    $t[] = <<<PHP
    public function testCrudWithOptimisticLocking(): void
    {
        \$r = \$this->api('POST', '/api/v1/{{slug}}', \$this->token, \$this->input() + ['unknown_field' => 'ignored']);
        self::assertSame(201, \$r->getStatusCode(), \$this->http->body());
        \$e = \$this->json(\$r)['data'];
        self::assertSame('/api/v1/{{slug}}/' . \$e['id'], \$r->getHeaderLine('Location'));
        self::assertSame('"1"', \$r->getHeaderLine('ETag'));
        self::assertArrayNotHasKey('unknown_field', \$e);

        \$r = \$this->api('GET', '/api/v1/{{slug}}/' . \$e['id'], \$this->token);
        self::assertSame(200, \$r->getStatusCode());
        self::assertSame(\$e['id'], \$this->json(\$r)['data']['id']);

        \$value = $v;
        \$this->assertError(\$this->api('PATCH', '/api/v1/{{slug}}/' . \$e['id'], \$this->token, [$k => \$value]), 428, 'precondition_required');
        \$r = \$this->api('PATCH', '/api/v1/{{slug}}/' . \$e['id'], \$this->token, [$k => \$value], ['If-Match' => '"1"']);
        self::assertSame(200, \$r->getStatusCode(), \$this->http->body());
        self::assertSame('"2"', \$r->getHeaderLine('ETag'));
        \$this->assertError(\$this->api('PATCH', '/api/v1/{{slug}}/' . \$e['id'], \$this->token, [$k => \$value], ['If-Match' => '"1"']), 409, 'stale_version');

        \$r = \$this->api('DELETE', '/api/v1/{{slug}}/' . \$e['id'], \$this->token, null, ['If-Match' => '"2"']);
        self::assertSame(204, \$r->getStatusCode(), \$this->http->body());
        \$this->assertError(\$this->api('GET', '/api/v1/{{slug}}/' . \$e['id'], \$this->token), 404, 'not_found');
    }
PHP;
}
if ($invalid !== []) {
    $t[] = <<<PHP
    public function testValidationErrorShape(): void
    {
        \$e = \$this->assertError(\$this->api('POST', '/api/v1/{{slug}}', \$this->token, [
__INVALID__
        ]), 422, 'validation_failed');
        foreach ([__KEYS__] as \$field) {
            self::assertIsList(\$e['details'][\$field] ?? null, \$field);
        }
    }
PHP;
    $t[count($t) - 1] = str_replace(['__INVALID__', '__KEYS__'], [implode("\n", $invalid), implode(', ', $invalidKeys)], $t[count($t) - 1]);
}
$t[] = <<<'PHP'
    public function testListPaginationScopesAndAuth(): void
    {
        $this->api('POST', '/api/v1/{{slug}}', $this->token, $this->input());
        $this->api('POST', '/api/v1/{{slug}}', $this->token, $this->input());
        $r = $this->api('GET', '/api/v1/{{slug}}?per_page=1&sort=-created_at', $this->token);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        $d = $this->json($r);
        self::assertCount(1, $d['data']);
        self::assertGreaterThanOrEqual(2, (int) $r->getHeaderLine('X-Total-Count'));
        self::assertNotNull($d['links']['next']);

        $this->assertError($this->api('GET', '/api/v1/{{slug}}', null), 401, 'unauthorized');
        // a token without the module scope is refused even though the user holds the permission
        $profileOnly = $this->token($this->admin, 'profile')['access_token'];
        $this->assertError($this->api('GET', '/api/v1/{{slug}}', $profileOnly), 403, 'insufficient_scope');
        // a user without {{code}}.create
        $user = Fixtures::user(['user']);
        $this->assertError($this->api('POST', '/api/v1/{{slug}}', $this->token($user)['access_token'], $this->input()), 403, 'forbidden');
    }
PHP;
if ($status !== null) {
    $second = $status->options[1];
    $first = $status->options[0];
    $t[] = <<<PHP
    public function testStatusTransitions(): void
    {
        \$e = \$this->json(\$this->api('POST', '/api/v1/{{slug}}', \$this->token, \$this->input()))['data'];
        self::assertSame('$first', \$e['status']);
        self::assertContains('$second', \$e['allowed_transitions']);
        \$r = \$this->api('POST', '/api/v1/{{slug}}/' . \$e['id'] . '/status', \$this->token, ['status' => '$second', 'comment' => 'API']);
        self::assertSame(200, \$r->getStatusCode(), \$this->http->body());
        self::assertSame('$second', \$this->json(\$r)['data']['status']);
        \$this->assertError(\$this->api('POST', '/api/v1/{{slug}}/' . \$e['id'] . '/status', \$this->token, ['status' => '$first']), 409, 'invalid_transition');
        \$this->assertError(\$this->api('POST', '/api/v1/{{slug}}/' . \$e['id'] . '/status', \$this->token, ['status' => 'bogus']), 422, 'validation_failed');
    }
PHP;
}

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Settings\ConfigRevision;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;

/**
 * /api/v1/{{slug}} over HTTP: CRUD, ETag/If-Match locking, validation shape, pagination, scopes, permissions__STATUS_DOC__.
 */
final class Api{{Module}}Test extends ApiTestCase
{
    private static bool $revisionBumped = false;
    private array $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$revisionBumped) {
            // a freshly migrated module flag must be visible to the running PHP-FPM workers (flag cache)
            TestContainer::service(ConfigRevision::class)->bump();
            self::$revisionBumped = true;
        }
        $this->admin = Fixtures::user(['business_admin']);
        $this->token = $this->token($this->admin)['access_token'];
    }

__TESTS__

    private function input(): array
    {
        return [
__VALID__
        ];
    }
}

PHP, [
    '__STATUS_DOC__' => $status ? ', lifecycle' : '',
    '__TESTS__' => implode("\n\n", $t),
    '__VALID__' => implode("\n", $valid),
]);
