<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;

/**
 * Tests: unit (domain), integration (service, jobs, consumer, connector), API and E2E tests for the
 * generated module — they cover exactly the features that were generated.
 */
final class TestsGenerator extends AbstractEnterpriseGenerator
{
    public static function getId(): string
    {
        return 'tests';
    }

    public static function getName(): string
    {
        return 'Tests';
    }

    public static function getDescription(): string
    {
        return 'Unit (domain), integration (service), API and E2E tests for the generated module.';
    }

    public static function requires(): array
    {
        return ['crud'];
    }

    public static function marker(ModuleSpec $m): ?string
    {
        return 'tests/Unit/' . $m->module . 'DomainTest.php';
    }

    protected function files(ModuleSpec $m): array
    {
        $files = [
            'tests/Unit/' . $m->module . 'DomainTest.php' => 'unit.php',
            'tests/Integration/' . $m->module . 'ServiceTest.php' => 'integration.php',
        ];
        if ($m->has('api')) {
            $files['tests/Api/Api' . $m->module . 'Test.php'] = 'api.php';
        }
        if ($m->has('admin') || $m->has('web')) {
            $files['tests/E2E/' . $m->module . 'UiTest.php'] = 'e2e.php';
        }
        return $files;
    }
}
