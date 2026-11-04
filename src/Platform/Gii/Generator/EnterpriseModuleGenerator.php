<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\ModuleSpec;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Validator\ValidatorInterface;
use Yiisoft\Yii\Gii\GeneratorCommandInterface;
use Yiisoft\Yii\Gii\ParametersProvider;

/**
 * Enterprise Module (composite): runs every generator enabled in the command's feature list
 * (dependencies resolved, fixed order) through yii-gii's generate() and merges their CodeFiles.
 * Files produced by several generators (rules, repository, jobs config) are rendered from the same
 * template and therefore identical; the CodeFile id (crc32 of the path) de-duplicates them.
 */
final class EnterpriseModuleGenerator extends AbstractEnterpriseGenerator
{
    /** @param array<string, AbstractEnterpriseGenerator> $generators id => generator */
    public function __construct(
        Aliases $aliases,
        ValidatorInterface $validator,
        ParametersProvider $parametersProvider,
        private readonly array $generators = [],
    ) {
        parent::__construct($aliases, $validator, $parametersProvider);
    }

    public static function getId(): string
    {
        return 'module';
    }

    public static function getName(): string
    {
        return 'Enterprise Module';
    }

    public static function getDescription(): string
    {
        return 'Composite: runs the selected generators (default: all) for a module name and a field spec, producing a fully integrated module.';
    }

    public static function requires(): array
    {
        return ['entity', 'crud', 'rbac', 'event', 'workflow', 'notification', 'job', 'report', 'scheduler', 'connector', 'admin', 'web', 'api', 'tests'];
    }

    public function getTemplatePath(GeneratorCommandInterface $command): string
    {
        return dirname(__DIR__) . '/templates';
    }

    protected function files(ModuleSpec $m): array
    {
        return [];
    }

    /**
     * Runs the sub-generators in order.
     *
     * @return array<string, array{0:string, 1:\Yiisoft\Yii\Gii\Component\CodeFile\CodeFile}> file id => [generator id, CodeFile]
     */
    public function generateAll(ModuleCommand $command): array
    {
        $this->generate($command); // validates the command once through yii-gii
        $files = [];
        foreach ($command->getSelected() as $id) {
            $generator = $this->generators[$id] ?? null;
            if ($generator === null) {
                continue;
            }
            foreach ($generator->generate($command) as $key => $file) {
                $files[$key] ??= [$id, $file];
            }
        }
        return $files;
    }
}
