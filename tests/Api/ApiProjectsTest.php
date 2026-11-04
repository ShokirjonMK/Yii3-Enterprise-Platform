<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Projects\Application\ProjectService;
use App\Platform\Identity\Infrastructure\UserRepository;
use App\Tests\Support\Fixtures;
use App\Tests\Support\TestContainer;

/**
 * /api/v1/projects: CRUD, validation shape, optimistic locking, lifecycle, tenant isolation,
 * pagination/sorting/filtering, scopes and permissions, reports.
 */
final class ApiProjectsTest extends ApiTestCase
{
    private array $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Fixtures::user(['business_admin']);
        $this->token = $this->token($this->admin)['access_token'];
    }

    public function testCreateViewUpdateWithOptimisticLocking(): void
    {
        $code = $this->code();
        $r = $this->api('POST', '/api/v1/projects', $this->token, [
            'code' => $code, 'name' => 'API project', 'priority' => 'high', 'budget' => 1250.5,
            'starts_on' => '2026-11-01', 'due_on' => '2026-12-31', 'tags' => ['api', 'Test'], 'unknown_field' => 'ignored',
        ]);
        self::assertSame(201, $r->getStatusCode(), $this->http->body());
        $p = $this->json($r)['data'];
        self::assertSame($code, $p['code']);
        self::assertSame('draft', $p['status']);
        self::assertSame('1250.50', $p['budget']);
        self::assertSame(['api', 'test'], $p['tags']);
        self::assertSame(['active', 'archived'], $p['allowed_transitions']);
        self::assertSame('/api/v1/projects/' . $p['id'], $r->getHeaderLine('Location'));
        self::assertSame('"1"', $r->getHeaderLine('ETag'));

        $r = $this->api('GET', '/api/v1/projects/' . $p['id'], $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('"1"', $r->getHeaderLine('ETag'));

        // PATCH without precondition → 428
        $this->assertError($this->api('PATCH', '/api/v1/projects/' . $p['id'], $this->token, ['name' => 'No version']), 428, 'precondition_required');

        $r = $this->api('PATCH', '/api/v1/projects/' . $p['id'], $this->token, ['name' => 'Renamed'], ['If-Match' => '"1"']);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        self::assertSame('Renamed', $this->json($r)['data']['name']);
        self::assertSame('"2"', $r->getHeaderLine('ETag'));

        // stale version → 409
        $e = $this->assertError($this->api('PATCH', '/api/v1/projects/' . $p['id'], $this->token, ['name' => 'Lost update'], ['If-Match' => '"1"']), 409, 'stale_version');
        self::assertStringContainsString('modified', $e['message']);
        // body version works as well
        self::assertSame(200, $this->api('PATCH', '/api/v1/projects/' . $p['id'], $this->token, ['description' => 'x', 'version' => 2])->getStatusCode());
    }

    public function testValidationErrorShape(): void
    {
        $r = $this->api('POST', '/api/v1/projects', $this->token, ['code' => '!', 'name' => '', 'starts_on' => '2026-05-10', 'due_on' => '2026-13-01', 'tags' => 'Bad Tag']);
        $e = $this->assertError($r, 422, 'validation_failed');
        self::assertSame('Validation failed.', $e['message']);
        foreach (['code', 'name', 'due_on', 'tags'] as $field) {
            self::assertIsList($e['details'][$field] ?? null, $field);
            self::assertIsString($e['details'][$field][0]);
        }
        $e = $this->assertError($this->api('POST', '/api/v1/projects', $this->token, ['code' => $this->code(), 'name' => 'Dates', 'starts_on' => '2026-05-10', 'due_on' => '2026-05-01']), 422, 'validation_failed');
        self::assertArrayHasKey('due_on', $e['details']);
    }

    public function testDuplicateCodeIs422(): void
    {
        $code = $this->code();
        self::assertSame(201, $this->api('POST', '/api/v1/projects', $this->token, ['code' => $code, 'name' => 'One'])->getStatusCode());
        $e = $this->assertError($this->api('POST', '/api/v1/projects', $this->token, ['code' => $code, 'name' => 'Two']), 422, 'validation_failed');
        self::assertArrayHasKey('code', $e['details']);
    }

    public function testLifecycleTransitionsAndArchive(): void
    {
        $p = $this->create();
        $r = $this->api('POST', "/api/v1/projects/{$p['id']}/status", $this->token, ['status' => 'completed']);
        $this->assertError($r, 409, 'invalid_transition');
        $r = $this->api('POST', "/api/v1/projects/{$p['id']}/status", $this->token, ['status' => 'active', 'comment' => 'Kick-off']);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        self::assertSame('active', $this->json($r)['data']['status']);
        $this->assertError($this->api('POST', "/api/v1/projects/{$p['id']}/status", $this->token, ['status' => 'bogus']), 422, 'validation_failed');
        // active projects cannot be archived
        $this->assertError($this->api('DELETE', '/api/v1/projects/' . $p['id'], $this->token), 409, 'invalid_transition');

        // a draft can be archived directly (no approval)
        $d = $this->create();
        $r = $this->api('DELETE', '/api/v1/projects/' . $d['id'], $this->token);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        self::assertSame('archived', $this->json($r)['data']['status']);
        $this->assertError($this->api('PATCH', '/api/v1/projects/' . $d['id'], $this->token, ['name' => 'x'], ['If-Match' => '"2"']), 409, 'project_archived');

        // completed → archived goes through the workflow approval when "project.lifecycle" is enabled
        $this->api('POST', "/api/v1/projects/{$p['id']}/status", $this->token, ['status' => 'completed']);
        $r = $this->api('DELETE', '/api/v1/projects/' . $p['id'], $this->token);
        self::assertContains($r->getStatusCode(), [200, 202], $this->http->body());
        if ($r->getStatusCode() === 202) {
            $d = $this->json($r);
            self::assertSame('completed', $d['data']['status']);
            self::assertSame('pending', $d['meta']['approval']['status']);
            self::assertNotEmpty($d['meta']['approval']['id']);
        }
    }

    public function testListPaginationSortingFiltering(): void
    {
        $tag = 'pg' . bin2hex(random_bytes(3));
        foreach (['C', 'A', 'B'] as $i => $letter) {
            $this->create(['name' => "Paging $letter", 'tags' => [$tag], 'priority' => $i === 0 ? 'critical' : 'low']);
        }
        $r = $this->api('GET', "/api/v1/projects?filter[tag]=$tag&sort=name&per_page=2&page=1&utm_source=ignored", $this->token);
        self::assertSame(200, $r->getStatusCode(), $this->http->body());
        $d = $this->json($r);
        self::assertSame(['Paging A', 'Paging B'], array_column($d['data'], 'name'));
        self::assertSame(['page' => 1, 'per_page' => 2, 'total' => 3, 'pages' => 2, 'sort' => 'name'], $d['meta']['pagination']);
        self::assertSame('3', $r->getHeaderLine('X-Total-Count'));
        self::assertNull($d['links']['prev']);
        self::assertStringContainsString('page=2', (string) $d['links']['next']);

        $d = $this->json($this->api('GET', "/api/v1/projects?filter[tag]=$tag&sort=-name&per_page=2&page=2", $this->token));
        self::assertSame(['Paging A'], array_column($d['data'], 'name'));
        self::assertNull($d['links']['next']);

        $d = $this->json($this->api('GET', "/api/v1/projects?filter[tag]=$tag&filter[priority]=critical", $this->token));
        self::assertSame(['Paging C'], array_column($d['data'], 'name'));
        $d = $this->json($this->api('GET', "/api/v1/projects?filter[tag]=$tag&sort=-priority", $this->token));
        self::assertSame('Paging C', $d['data'][0]['name']);

        $d = $this->json($this->api('GET', "/api/v1/projects?filter[tag]=$tag&filter[status]=active", $this->token));
        self::assertSame(0, $d['meta']['pagination']['total']);
        $d = $this->json($this->api('GET', '/api/v1/projects?q=' . rawurlencode('Paging B') . "&filter[tag]=$tag", $this->token));
        self::assertSame(['Paging B'], array_column($d['data'], 'name'));

        $this->assertError($this->api('GET', '/api/v1/projects?sort=password', $this->token), 422, 'validation_failed');
        $this->assertError($this->api('GET', '/api/v1/projects?per_page=101', $this->token), 422, 'validation_failed');
        $this->assertError($this->api('GET', '/api/v1/projects?page=0', $this->token), 422, 'validation_failed');
    }

    public function testForeignTenantProjectIs404(): void
    {
        $db = Fixtures::db();
        $tenant = (string) $db->createCommand("INSERT INTO tenants (code, name) VALUES (:c, 'API foreign tenant') RETURNING id", [':c' => 't-' . bin2hex(random_bytes(4))])->queryScalar();
        $foreign = Fixtures::user(['business_admin'], extra: ['tenant_id' => $tenant]);
        $user = TestContainer::service(UserRepository::class)->find($foreign['id']);
        $project = TestContainer::service(ProjectService::class)->create($user, ['code' => $this->code(), 'name' => 'Foreign']);

        $this->assertError($this->api('GET', '/api/v1/projects/' . $project->id, $this->token), 404, 'not_found');
        $this->assertError($this->api('PATCH', '/api/v1/projects/' . $project->id, $this->token, ['name' => 'x'], ['If-Match' => '"1"']), 404, 'not_found');
        $this->assertError($this->api('DELETE', '/api/v1/projects/' . $project->id, $this->token), 404, 'not_found');
        $this->assertError($this->api('GET', '/api/v1/projects/00000000-0000-4000-8000-000000000000', $this->token), 404, 'not_found');
    }

    public function testScopeAndPermissionChecks(): void
    {
        // read-only scope cannot write
        $readOnly = $this->token($this->admin, 'projects.read')['access_token'];
        self::assertSame(200, $this->api('GET', '/api/v1/projects', $readOnly)->getStatusCode());
        $this->assertError($this->api('POST', '/api/v1/projects', $readOnly, ['code' => $this->code(), 'name' => 'Nope']), 403, 'insufficient_scope');
        $this->assertError($this->api('GET', '/api/v1/users', $readOnly), 403, 'insufficient_scope');

        // role "user" holds projects.view but not projects.create
        $plain = $this->token(Fixtures::user(['user']))['access_token'];
        self::assertSame(200, $this->api('GET', '/api/v1/projects', $plain)->getStatusCode());
        $this->assertError($this->api('POST', '/api/v1/projects', $plain, ['code' => $this->code(), 'name' => 'Nope']), 403, 'forbidden');
        $p = $this->create();
        $this->assertError($this->api('PATCH', '/api/v1/projects/' . $p['id'], $plain, ['name' => 'x'], ['If-Match' => '"1"']), 403, 'forbidden');
    }

    public function testReportIsAccepted(): void
    {
        $this->create();
        $r = $this->api('POST', '/api/v1/projects/reports', $this->token, ['status' => 'draft']);
        self::assertSame(202, $r->getStatusCode(), $this->http->body());
        $d = $this->json($r)['data'];
        self::assertNotEmpty($d['job_id']);
        self::assertContains($d['status'], ['pending', 'running', 'done']);
        self::assertSame('/api/v1/projects/reports/' . $d['id'], $r->getHeaderLine('Location'));
        $r = $this->api('GET', '/api/v1/projects/reports/' . $d['id'], $this->token);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame($d['id'], $this->json($r)['data']['id']);
    }

    private function create(array $extra = []): array
    {
        $r = $this->api('POST', '/api/v1/projects', $this->token, $extra + ['code' => $this->code(), 'name' => 'Test project']);
        self::assertSame(201, $r->getStatusCode(), $this->http->body());
        return $this->json($r)['data'];
    }

    private function code(): string
    {
        return 'API-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
