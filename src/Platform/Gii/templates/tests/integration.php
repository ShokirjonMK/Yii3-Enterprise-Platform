<?php

declare(strict_types=1);

/**
 * Template: integration test of the application service (tests/Integration/<Module>ServiceTest.php).
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
$required = array_values(array_filter($m->editable(), static fn($f) => $f->required && $f->type !== 'user'));
$display = $m->displayField();
$updateField = null;
foreach ($m->editable() as $f) {
    if ($f->type !== 'user') {
        $updateField = $f;
        break;
    }
}
$userField = $m->ofType('user')[0] ?? null;
$event = $m->has('event');
$t = [];

$t[] = <<<'PHP'
    public function testCreateWritesRowAndAuditAtomically(): void
    {
        $e = $this->service->create($this->admin, $this->input());
        self::assertSame(1, $e->version());
        self::assertSame($this->admin->tenantId, $e->tenantId);
        self::assertTrue((new Query($this->db))->from('audit_log')->where(['entity_type' => '{{entity_snake}}', 'entity_id' => $e->id, 'action' => '{{entity_snake}}.created'])->exists());
__OUTBOX__
        // one transaction: rolling back the outer transaction removes row, audit__AND_EVENT__
        $tx = $this->db->beginTransaction();
        $rolled = $this->service->create($this->admin, $this->input());
        $tx->rollBack();
        self::assertFalse((new Query($this->db))->from('{{table}}')->where(['id' => $rolled->id])->exists());__ROLLBACK_OUTBOX__
    }
PHP;
$t[0] = strtr($t[0], [
    '__OUTBOX__' => $event ? "        \$outbox = (new Query(\$this->db))->from('outbox')->where(['aggregate_id' => \$e->id])->all();\n        self::assertCount(1, \$outbox);\n        self::assertSame('{{topic}}', \$outbox[0]['topic']);\n        self::assertSame({{Entity}}::EVENT_CREATED, \$outbox[0]['event_type']);\n" : '',
    '__AND_EVENT__' => $event ? ' and event' : '',
    '__ROLLBACK_OUTBOX__' => $event ? "\n        self::assertFalse((new Query(\$this->db))->from('outbox')->where(['aggregate_id' => \$rolled->id])->exists());" : '',
]);

if ($required !== []) {
    $keys = implode(', ', array_map(static fn($f) => $q($f->name), $required));
    $t[] = <<<PHP
    public function testValidationErrorsArePerField(): void
    {
        try {
            \$this->service->create(\$this->admin, []);
            self::fail('ValidationException expected');
        } catch (ValidationException \$e) {
            foreach ([$keys] as \$field) {
                self::assertArrayHasKey(\$field, \$e->errors(), \$field);
            }
        }
    }
PHP;
}
foreach ($m->unique() as $f) {
    $t[] = <<<PHP
    public function testUnique{$f->pascal()}PerTenant(): void
    {
        \$input = \$this->input();
        \$this->service->create(\$this->admin, \$input);
        try {
            \$this->service->create(\$this->admin, \$input);
            self::fail('duplicate accepted');
        } catch (ValidationException \$e) {
            self::assertArrayHasKey({$q($f->name)}, \$e->errors());
        }
        // the same value is fine in another tenant
        self::assertSame(\$this->foreignTenant, \$this->service->create(\$this->foreignAdmin, \$input)->tenantId);
    }
PHP;
}
$t[] = <<<'PHP'
    public function testTenantIsolationReportsForeignRecordsAsNotFound(): void
    {
        $e = $this->service->create($this->admin, $this->input());
        try {
            $this->service->get($this->foreignAdmin, $e->id);
            self::fail('foreign tenant could read the record');
        } catch (NotFoundException) {
        }
        $this->expectException(NotFoundException::class);
        $this->service->update($this->foreignAdmin, $e->id, [], 1);
    }
PHP;
if ($display !== null && $display->isSearchable()) {
    $k = $q($display->name);
    $t[] = <<<PHP
    public function testSearchIsTenantScoped(): void
    {
        \$input = \$this->input();
        \$this->service->create(\$this->foreignAdmin, \$input);
        self::assertSame(0, \$this->service->search(\$this->admin, ['q' => \$input[$k]], 1, 50, null)->total);
        self::assertSame(1, \$this->service->search(\$this->foreignAdmin, ['q' => \$input[$k]], 1, 50, null)->total);
    }
PHP;
}
if ($updateField !== null) {
    $k = $q($updateField->name);
    $v = FieldCode::sample($updateField, 1);
    $t[] = <<<PHP
    public function testOptimisticLocking(): void
    {
        \$e = \$this->service->create(\$this->admin, \$this->input());
        \$value = $v;
        \$v2 = \$this->service->update(\$this->admin, \$e->id, [$k => \$value], 1);
        self::assertSame(2, \$v2->version());
        try {
            \$this->service->update(\$this->admin, \$e->id, [$k => \$value], 1);
            self::fail('stale write accepted');
        } catch (ConflictException \$ex) {
            self::assertSame('stale_version', \$ex->errorCode());
        }
        self::assertSame(2, \$this->service->get(\$this->admin, \$e->id)->version());
    }
PHP;
}
$t[] = <<<'PHP'
    public function testPermissionsAreEnforced(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->service->create($this->member, $this->input());
    }
PHP;
if ($status !== null) {
    $second = $status->caseNames()[1];
    $ownerInput = $userField !== null ? "[{$q($userField->name)} => \$this->member->id]" : '[]';
    $notify = $m->has('notification') && $userField !== null
        ? "\n        self::assertGreaterThanOrEqual(1, (int) (new Query(\$this->db))->from('notifications')\n            ->where(['user_id' => \$this->member->id, 'template_code' => '{$m->notifyTemplate()}'])->count(), 'referenced user notified');"
        : '';
    $wf = $m->has('workflow')
        ? "\n        \$instance = (new Query(\$this->db))->select('i.state')->from(['i' => 'workflow_instances'])->innerJoin(['w' => 'workflows'], 'w.id = i.workflow_id')\n            ->where(['w.code' => '{$m->workflowCode()}', 'i.entity_id' => \$e->id])->scalar();\n        if (\$instance !== false && \$instance !== null) {\n            self::assertSame({{Entity}}Status::{$second}->value, \$instance, 'workflow instance mirrors the state');\n        }"
        : '';
    $outbox = $event ? "\n        self::assertTrue((new Query(\$this->db))->from('outbox')->where(['aggregate_id' => \$e->id, 'event_type' => {{Entity}}::EVENT_STATUS_CHANGED])->exists());" : '';
    $t[] = <<<PHP
    public function testStatusTransitions(): void
    {
        \$e = \$this->service->create(\$this->admin, $ownerInput + \$this->input());
        self::assertSame({{Entity}}Status::initial(), \$e->status());
        \$next = \$this->service->changeStatus(\$this->admin, \$e->id, {{Entity}}Status::{$second}->value, 1, 'go');
        self::assertSame({{Entity}}Status::$second, \$next->status());
        self::assertSame(2, \$next->version());$outbox$notify$wf
        try {
            \$this->service->changeStatus(\$this->admin, \$e->id, {{Entity}}Status::initial()->value);
            self::fail('backwards transition accepted');
        } catch (ConflictException \$ex) {
            self::assertSame('invalid_transition', \$ex->errorCode());
        }
        \$this->expectException(ValidationException::class);
        \$this->service->changeStatus(\$this->admin, \$e->id, 'bogus');
    }
PHP;
} elseif ($m->has('notification') && $userField !== null) {
    $t[] = <<<PHP
    public function testCreationNotifiesReferencedUser(): void
    {
        \$this->service->create(\$this->admin, [{$q($userField->name)} => \$this->member->id] + \$this->input());
        self::assertGreaterThanOrEqual(1, (int) (new Query(\$this->db))->from('notifications')
            ->where(['user_id' => \$this->member->id, 'template_code' => '{$m->notifyTemplate()}'])->count());
    }
PHP;
}
$deletedOutbox = $event ? "\n        self::assertTrue((new Query(\$this->db))->from('outbox')->where(['aggregate_id' => \$e->id, 'event_type' => {{Entity}}::EVENT_DELETED])->exists());" : '';
$t[] = <<<PHP
    public function testDeleteIsSoftAndHidesTheRecord(): void
    {
        \$e = \$this->service->create(\$this->admin, \$this->input());
        \$this->service->delete(\$this->admin, \$e->id, 1);
        self::assertNotNull((new Query(\$this->db))->select('deleted_at')->from('{{table}}')->where(['id' => \$e->id])->scalar());$deletedOutbox
        \$this->expectException(NotFoundException::class);
        \$this->service->get(\$this->admin, \$e->id);
    }
PHP;
if ($event) {
    $t[] = <<<'PHP'
    public function testConsumerBuildsTheReadModelIdempotently(): void
    {
        $e = $this->service->create($this->admin, $this->input());
        $row = (new Query($this->db))->from('outbox')->where(['aggregate_id' => $e->id])->one();
        self::assertNotNull($row);
        $envelope = new EventEnvelope((string) $row['id'], (string) $row['event_type'], 1, (string) $row['aggregate_type'], (string) $row['aggregate_id'],
            Sql::json($row['payload']), date(DATE_ATOM), null, $e->tenantId, $this->admin->id);
        $projector = $this->get({{Entity}}ActivityProjector::class);
        $projector->handle($envelope);
        $projector->handle($envelope); // replay
        self::assertSame(1, (int) (new Query($this->db))->from('{{entity_snake}}_activity')->where(['entity_id' => $e->id])->count());
        $activity = $this->service->activity($this->admin, $e->id);
        self::assertSame('read_model', $activity['source']);
        self::assertStringContainsString('created', $activity['items'][0]['summary']);
    }
PHP;
}
if ($m->has('job')) {
    $t[] = <<<'PHP'
    public function testCleanupJobPurgesOldDeletedRows(): void
    {
        $old = $this->service->create($this->admin, $this->input());
        $fresh = $this->service->create($this->admin, $this->input());
        $this->service->delete($this->admin, $old->id);
        $this->service->delete($this->admin, $fresh->id);
        $this->db->createCommand("UPDATE {{table}} SET deleted_at = now() - interval '100 days' WHERE id = :id", [':id' => $old->id])->execute();
        $this->get(PurgeDeleted{{Module}}Job::class)->handle(['retention_days' => 90], $this->context('{{code}}.cleanup'));
        self::assertFalse((new Query($this->db))->from('{{table}}')->where(['id' => $old->id])->exists(), 'old deleted row purged');
        self::assertTrue((new Query($this->db))->from('{{table}}')->where(['id' => $fresh->id])->exists(), 'recently deleted row kept');
    }
PHP;
}
if ($m->has('report')) {
    $t[] = <<<'PHP'
    public function testReportJobWritesCsvToObjectStorage(): void
    {
        $storage = $this->get(ObjectStorage::class);
        if (!$storage->isConfigured()) {
            self::markTestSkipped('Object storage: Not configured.');
        }
        $this->service->create($this->admin, $this->input());
        $r = $this->service->requestReport($this->admin);
        $this->get(Generate{{Module}}ReportJob::class)->handle(['report_id' => $r['report_id']], $this->context('{{code}}.report.generate'));
        $report = $this->service->report($this->admin, $r['report_id']);
        self::assertSame('done', $report['status'], (string) $report['error']);
        self::assertGreaterThanOrEqual(1, (int) $report['rows_count']);
        $csv = $storage->filesystem()->read((string) $report['path']);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('created_at', $csv);
    }
PHP;
}
if ($m->has('connector')) {
    $t[] = <<<'PHP'
    public function testConnectorIsNotConfiguredUntilAnOperatorEnablesIt(): void
    {
        $connector = $this->get({{Module}}Connector::class);
        self::assertFalse($connector->isConfigured());
        $e = $this->service->create($this->admin, $this->input());
        $this->expectException(IntegrationException::class);
        $connector->push($e);
    }
PHP;
}

$uses = ['use App\Modules\{{Module}}\Application\{{Entity}}Service;', 'use App\Modules\{{Module}}\Domain\{{Entity}};'];
if ($status) {
    $uses[] = 'use App\Modules\{{Module}}\Domain\{{Entity}}Status;';
}
if ($event) {
    $uses[] = 'use App\Modules\{{Module}}\Infrastructure\{{Entity}}ActivityProjector;';
}
if ($m->has('connector')) {
    $uses[] = 'use App\Modules\{{Module}}\Infrastructure\{{Module}}Connector;';
}
if ($m->has('report')) {
    $uses[] = 'use App\Modules\{{Module}}\Infrastructure\Generate{{Module}}ReportJob;';
}
if ($m->has('job')) {
    $uses[] = 'use App\Modules\{{Module}}\Infrastructure\PurgeDeleted{{Module}}Job;';
}
$uses[] = 'use App\Platform\Identity\Domain\User;';
$uses[] = 'use App\Platform\Identity\Infrastructure\UserRepository;';
if ($m->has('connector')) {
    $uses[] = 'use App\Platform\Integrations\Exception\IntegrationException;';
}
if ($event) {
    $uses[] = 'use App\Platform\Messaging\EventEnvelope;';
}
if ($m->has('job') || $m->has('report')) {
    $uses[] = 'use App\Platform\Queue\JobContext;';
}
if ($m->has('report')) {
    $uses[] = 'use App\Platform\Storage\ObjectStorage;';
}
array_push($uses, 'use App\Shared\Domain\Exception\AccessDeniedException;', 'use App\Shared\Domain\Exception\ConflictException;', 'use App\Shared\Domain\Exception\NotFoundException;', 'use App\Shared\Domain\Exception\ValidationException;');
if ($event) {
    $uses[] = 'use App\Shared\Infrastructure\Database\Sql;';
}
array_push($uses, 'use App\Tests\Support\Fixtures;', 'use Yiisoft\Db\Query\Query;');

$context = ($m->has('job') || $m->has('report')) ? <<<'PHP'

    private function context(string $type): JobContext
    {
        return new JobContext('test-' . bin2hex(random_bytes(4)), $type, 1, null, static fn(): bool => false, static fn(int $p): null => null);
    }
PHP : '';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Tests\Integration;

__USES__

/**
 * {{Entity}}Service against the real test database: audit__OUTBOX_DOC__ in one transaction, tenant isolation,
 * optimistic locking, RBAC, soft delete and the generated jobs / consumer / connector.
 */
final class {{Module}}ServiceTest extends IntegrationTestCase
{
    private {{Entity}}Service $service;
    private User $admin;
    private User $member;
    private User $foreignAdmin;
    private string $foreignTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->get({{Entity}}Service::class);
        $this->foreignTenant = (string) $this->db->createCommand(
            "INSERT INTO tenants (code, name) VALUES (:c, 'Foreign test tenant') RETURNING id",
            [':c' => 't-' . bin2hex(random_bytes(4))],
        )->queryScalar();
        $this->admin = $this->user(Fixtures::user(['business_admin']));
        $this->member = $this->user(Fixtures::user(['user']));
        $this->foreignAdmin = $this->user(Fixtures::user(['business_admin'], extra: ['tenant_id' => $this->foreignTenant]));
    }

__TESTS__

    /** @return array<string,mixed> valid input (random values keep runs independent) */
    private function input(): array
    {
        return [
__VALID__
        ];
    }

    private function user(array $fixture): User
    {
        $user = $this->get(UserRepository::class)->find($fixture['id']);
        self::assertNotNull($user);
        return $user;
    }__CONTEXT__
}

PHP, [
    '__USES__' => implode("\n", $uses),
    '__OUTBOX_DOC__' => $event ? ' + outbox' : '',
    '__TESTS__' => implode("\n\n", $t),
    '__VALID__' => implode("\n", $valid),
    '__CONTEXT__' => $context,
]);
