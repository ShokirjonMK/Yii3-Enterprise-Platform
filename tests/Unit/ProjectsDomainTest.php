<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectEvent;
use App\Modules\Projects\Domain\ProjectRules;
use App\Modules\Projects\Domain\ProjectStatus;
use App\Modules\Projects\Infrastructure\GenerateProjectsReportJob;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectsDomainTest extends TestCase
{
    public static function transitions(): iterable
    {
        $all = ProjectStatus::cases();
        $allowed = [
            'draft' => ['active', 'archived'],
            'active' => ['on_hold', 'completed'],
            'on_hold' => ['active', 'completed'],
            'completed' => ['archived'],
            'archived' => [],
        ];
        foreach ($all as $from) {
            foreach ($all as $to) {
                yield $from->value . '→' . $to->value => [$from, $to, in_array($to->value, $allowed[$from->value], true)];
            }
        }
    }

    #[DataProvider('transitions')]
    public function testStateMachineMatrix(ProjectStatus $from, ProjectStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
        if (!$expected) {
            try {
                $from->assertTransitionTo($to);
                self::fail('Expected ConflictException');
            } catch (ConflictException $e) {
                self::assertSame('invalid_transition', $e->errorCode());
                self::assertSame(409, $e->httpStatus());
            }
        }
    }

    public function testArchivedIsTerminal(): void
    {
        self::assertTrue(ProjectStatus::Archived->isTerminal());
        self::assertSame([], ProjectStatus::Archived->allowedTransitions());
    }

    public function testCreateNormalisesAndRecordsEvent(): void
    {
        $p = $this->project(['code' => 'crm-1', 'name' => '  CRM   rollout ', 'tags' => 'Alpha, beta,alpha', 'budget' => '1 500,5']);
        self::assertSame('CRM-1', $p->code());
        self::assertSame('CRM rollout', $p->name());
        self::assertSame(['alpha', 'beta'], $p->tags());
        self::assertSame('1500.50', $p->budget());
        self::assertSame(ProjectStatus::Draft, $p->status());
        $events = $p->pullEvents();
        self::assertCount(1, $events);
        self::assertSame(ProjectEvent::CREATED, $events[0]->type);
        self::assertSame([], $p->pullEvents(), 'events are pulled once');
    }

    public function testValidationCollectsAllFieldErrors(): void
    {
        try {
            ProjectRules::normalize([
                'code' => '!', 'name' => 'x', 'priority' => 'urgent', 'budget' => '-5', 'starts_on' => '2026-02-30',
                'tags' => ['ok', 'Bad Tag!'], 'owner_id' => 'nope', 'description' => str_repeat('a', 5001),
            ], true);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->httpStatus());
            foreach (['code', 'name', 'priority', 'budget', 'starts_on', 'tags', 'owner_id', 'description'] as $field) {
                self::assertArrayHasKey($field, $e->errors(), $field);
            }
        }
    }

    public function testTooManyTags(): void
    {
        $this->expectException(ValidationException::class);
        ProjectRules::normalize(['code' => 'AB', 'name' => 'Ok', 'tags' => array_map(static fn($i) => "t$i", range(1, 11))], true);
    }

    public function testDueDateCannotPrecedeStartDate(): void
    {
        try {
            $this->project(['starts_on' => '2026-05-10', 'due_on' => '2026-05-01']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('due_on', $e->errors());
        }
        // partial update is checked against the current state
        $p = $this->project(['starts_on' => '2026-05-10', 'due_on' => '2026-06-01']);
        $this->expectException(ValidationException::class);
        $p->update(['due_on' => '2026-05-09']);
    }

    public function testUpdateReportsOnlyRealChanges(): void
    {
        $p = $this->project(['name' => 'Alpha', 'priority' => 'low']);
        $p->pullEvents();
        $changes = $p->update(['name' => 'Alpha', 'priority' => 'high', 'status' => 'archived', 'tenant_id' => 'x']);
        self::assertSame(['priority' => ['low', 'high']], $changes);
        self::assertSame(ProjectStatus::Draft, $p->status(), 'status is not editable through update()');
        self::assertSame(ProjectEvent::UPDATED, $p->pullEvents()[0]->type);
        self::assertSame([], $p->update(['name' => 'Alpha']));
        self::assertSame([], $p->pullEvents());
    }

    public function testLifecycleAndArchivedReadOnly(): void
    {
        $p = $this->project();
        $p->changeStatus(ProjectStatus::Active);
        $p->changeStatus(ProjectStatus::OnHold);
        $p->changeStatus(ProjectStatus::Active);
        $p->changeStatus(ProjectStatus::Completed);
        $p->changeStatus(ProjectStatus::Archived);
        self::assertNotNull($p->archivedAt());
        $types = array_map(static fn(ProjectEvent $e) => $e->type, $p->pullEvents());
        self::assertSame(ProjectEvent::ARCHIVED, end($types));
        $this->expectException(ConflictException::class);
        $p->update(['name' => 'Changed']);
    }

    public function testCannotCompleteDraft(): void
    {
        $this->expectException(ConflictException::class);
        $this->project()->changeStatus(ProjectStatus::Completed);
    }

    public function testCsvInjectionIsNeutralised(): void
    {
        self::assertSame("'=HYPERLINK(\"x\")", GenerateProjectsReportJob::safeCell('=HYPERLINK("x")'));
        self::assertSame("'@cmd", GenerateProjectsReportJob::safeCell('@cmd'));
        self::assertSame('-12.50', GenerateProjectsReportJob::safeCell('-12.50'));
        self::assertSame('plain', GenerateProjectsReportJob::safeCell('plain'));
    }

    private function project(array $input = []): Project
    {
        return Project::create(Uuid::v7(), Uuid::v7(), $input + ['code' => 'PRJ-1', 'name' => 'Project one'], Uuid::v7(), null);
    }
}
