<?php

declare(strict_types=1);

/**
 * Template: unit test of the domain (tests/Unit/<Module>DomainTest.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

use App\Platform\Gii\Spec\FieldCode;

$q = [FieldCode::class, 'q'];
$status = $m->statusField();
$valid = [];
foreach ($m->editable() as $f) {
    $s = FieldCode::sample($f);
    if ($f->type === 'user') {
        $s = 'Uuid::v7()';
    }
    if ($s !== null) {
        $valid[] = '            ' . $q($f->name) . ' => ' . $s . ',';
    }
}
$invalid = [];
$invalidKeys = [];
foreach ($m->editable() as $f) {
    $bad = FieldCode::invalid($f);
    if ($bad !== null) {
        $invalid[] = '            ' . $q($f->name) . ' => ' . $bad . ',';
        $invalidKeys[] = $q($f->name);
    }
}
$required = array_map(static fn($f) => $q($f->name), array_filter($m->editable(), static fn($f) => $f->required));
$display = $m->displayField();
$updateField = $display ?? ($m->editable()[0] ?? null);
$updateValue = $updateField !== null ? (FieldCode::sample($updateField, 1) ?? 'Uuid::v7()') : null;
$decimal = $m->ofType('decimal')[0] ?? null;
if ($decimal !== null && (($decimal->min !== null && $decimal->min > 1234.5) || ($decimal->max !== null && $decimal->max < 1234.5))) {
    $decimal = null;
}

$statusTests = '';
if ($status !== null) {
    $matrix = [];
    foreach ($status->options as $i => $o) {
        $matrix[] = "            '$o' => [" . implode(', ', array_map(static fn($x) => "'$x'", array_slice($status->options, $i + 1))) . '],';
    }
    $second = $status->caseNames()[1];
    $last = $status->caseNames()[count($status->options) - 1];
    $statusTests = <<<PHP

    public static function transitions(): iterable
    {
        \$allowed = [
__MATRIX__
        ];
        foreach ({{Entity}}Status::cases() as \$from) {
            foreach ({{Entity}}Status::cases() as \$to) {
                yield \$from->value . '→' . \$to->value => [\$from, \$to, in_array(\$to->value, \$allowed[\$from->value], true)];
            }
        }
    }

    #[DataProvider('transitions')]
    public function testStateMachineMatrix({{Entity}}Status \$from, {{Entity}}Status \$to, bool \$expected): void
    {
        self::assertSame(\$expected, \$from->canTransitionTo(\$to));
        if (!\$expected) {
            try {
                \$from->assertTransitionTo(\$to);
                self::fail('Expected ConflictException');
            } catch (ConflictException \$e) {
                self::assertSame('invalid_transition', \$e->errorCode());
                self::assertSame(409, \$e->httpStatus());
            }
        }
    }

    public function testTerminalStateIsReadOnly(): void
    {
        \$e = \$this->entity();
        self::assertSame({{Entity}}Status::initial(), \$e->status());
        \$from = \$e->changeStatus({{Entity}}Status::$last, 'done');
        self::assertSame({{Entity}}Status::initial(), \$from);
        self::assertTrue(\$e->status()->isTerminal());
        self::assertSame([], \$e->status()->allowedTransitions());
        \$this->expectException(ConflictException::class);
        \$e->update([]);
    }

    public function testStatusChangeRecordsEvent(): void
    {
        \$e = \$this->entity();
        \$e->pullEvents();
        \$e->changeStatus({{Entity}}Status::$second);
        \$events = \$e->pullEvents();
        self::assertSame({{Entity}}::EVENT_STATUS_CHANGED, \$events[0]['type']);
        self::assertSame('{$status->options[1]}', \$events[0]['payload']['to']);
    }
PHP;
    $statusTests = str_replace('__MATRIX__', implode("\n", $matrix), $statusTests);
}

$updateTest = $updateField !== null ? <<<PHP

    public function testUpdateReportsChangesAndRecordsEvent(): void
    {
        \$e = \$this->entity();
        \$e->pullEvents();
        \$value = $updateValue;
        \$changes = \$e->update([{$q($updateField->name)} => \$value, 'unknown_field' => 'ignored']);
        self::assertArrayHasKey({$q($updateField->name)}, \$changes);
        self::assertSame({{Entity}}::EVENT_UPDATED, \$e->pullEvents()[0]['type']);
        self::assertSame([], \$e->update([{$q($updateField->name)} => \$value]), 'no-op update');
    }
PHP : '';

$decimalTest = $decimal !== null ? <<<PHP

    public function testDecimalIsNormalised(): void
    {
        \$values = {{Entity}}Rules::normalize([{$q($decimal->name)} => '1 234,5'], false);
        self::assertSame('1234.50', \$values[{$q($decimal->name)}]);
    }
PHP : '';

$invalidTest = $invalid !== [] ? <<<PHP

    public function testInvalidValuesAreRejectedPerField(): void
    {
        try {
            {{Entity}}Rules::normalize([
__INVALID__
            ], false);
            self::fail('ValidationException expected');
        } catch (ValidationException \$e) {
            foreach ([__INVALID_KEYS__] as \$field) {
                self::assertArrayHasKey(\$field, \$e->errors(), \$field);
            }
        }
    }
PHP : '';
$invalidTest = str_replace(['__INVALID__', '__INVALID_KEYS__'], [implode("\n", $invalid), implode(', ', $invalidKeys)], $invalidTest);

$requiredTest = $required !== [] ? <<<PHP

    public function testRequiredFieldsAreValidated(): void
    {
        try {
            {{Entity}}Rules::normalize([], true);
            self::fail('ValidationException expected');
        } catch (ValidationException \$e) {
            foreach ([__REQ__] as \$field) {
                self::assertArrayHasKey(\$field, \$e->errors(), \$field);
            }
        }
    }
PHP : '';
$requiredTest = str_replace('__REQ__', implode(', ', $required), $requiredTest);

$uses = ['use App\Modules\{{Module}}\Domain\{{Entity}};', 'use App\Modules\{{Module}}\Domain\{{Entity}}Rules;'];
if ($status) {
    $uses[] = 'use App\Modules\{{Module}}\Domain\{{Entity}}Status;';
}
array_push($uses, 'use App\Shared\Domain\Exception\ConflictException;', 'use App\Shared\Domain\Exception\ValidationException;', 'use App\Shared\Domain\Uuid;');
if ($status) {
    $uses[] = 'use PHPUnit\Framework\Attributes\DataProvider;';
}
$uses[] = 'use PHPUnit\Framework\TestCase;';

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Tests\Unit;

__USES__

/**
 * {{label}} domain: creation/normalisation, validation per field, updates__STATUS_DOC__, soft delete.
 */
final class {{Module}}DomainTest extends TestCase
{
    public function testCreateNormalisesAndRecordsEvent(): void
    {
        $e = $this->entity();
        self::assertSame(1, $e->version());
        self::assertFalse($e->isDeleted());
        $events = $e->pullEvents();
        self::assertCount(1, $events);
        self::assertSame({{Entity}}::EVENT_CREATED, $events[0]['type']);
        self::assertSame($e->id, $events[0]['payload']['id']);
        self::assertSame([], $e->pullEvents(), 'events are drained');
        foreach ({{Entity}}Rules::FIELDS as $field) {
            self::assertArrayHasKey($field, $e->values());
        }
    }
__REQUIRED_TEST____INVALID_TEST____DECIMAL_TEST____UPDATE_TEST____STATUS_TESTS__

    public function testDeleteIsSoftAndFinal(): void
    {
        $e = $this->entity();
        $e->markDeleted();
        self::assertTrue($e->isDeleted());
        self::assertNotNull($e->toRow()['deleted_at']);
        $this->expectException(ConflictException::class);
        $e->markDeleted();
    }

    private function entity(): {{Entity}}
    {
        return {{Entity}}::create(Uuid::v7(), Uuid::v7(), [
__VALID__
        ], Uuid::v7());
    }
}

PHP, [
    '__USES__' => implode("\n", $uses),
    '__STATUS_DOC__' => $status ? ', lifecycle state machine' : '',
    '__REQUIRED_TEST__' => $requiredTest,
    '__INVALID_TEST__' => $invalidTest,
    '__DECIMAL_TEST__' => $decimalTest,
    '__UPDATE_TEST__' => $updateTest,
    '__STATUS_TESTS__' => $statusTests,
    '__VALID__' => implode("\n", $valid),
]);
