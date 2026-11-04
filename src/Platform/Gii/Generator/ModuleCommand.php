<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\Spec\FieldSpecParser;
use App\Platform\Gii\Spec\ModuleSpec;
use Yiisoft\Validator\Rule\Regex;
use Yiisoft\Validator\Rule\Required;
use Yiisoft\Yii\Gii\Generator\AbstractGeneratorCommand;
use Yiisoft\Yii\Gii\Validator\TemplateRule;

/**
 * yii-gii generator command (input DTO) shared by all Enterprise Gii generators. It is validated by
 * yii-gii's {@see \Yiisoft\Yii\Gii\Generator\AbstractGenerator::generate()} through yiisoft/validator
 * (attribute rules below) before any template is rendered.
 */
final class ModuleCommand extends AbstractGeneratorCommand
{
    private ?ModuleSpec $spec = null;

    /**
     * @param list<string> $features generators whose code exists or is being generated (template switches)
     * @param list<string> $selected generators to run (dependencies included)
     */
    public function __construct(
        #[Required(message: 'The module name is required.')]
        #[Regex(pattern: ModuleSpec::NAME, message: 'The module name must match ^[A-Z][A-Za-z0-9]{2,40}$ (PascalCase, e.g. Contracts).')]
        private readonly string $moduleName = '',
        #[Required(message: 'At least one field is required.')]
        private readonly string $fields = '',
        #[Required(message: 'The generator root path is required.')]
        private readonly string $rootPath = '',
        private readonly array $features = [],
        private readonly array $selected = [],
        #[Required(message: 'A code template must be selected.')]
        #[TemplateRule]
        protected string $template = 'default',
    ) {
        parent::__construct($template);
    }

    public function spec(): ModuleSpec
    {
        return $this->spec ??= new ModuleSpec($this->moduleName, FieldSpecParser::parse($this->fields), $this->features, $this->rootPath);
    }

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    /** @return list<string> */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /** @return list<string> generators to run (dependencies included) */
    public function getSelected(): array
    {
        return $this->selected;
    }

    public static function getAttributes(): array
    {
        return ['moduleName', 'fields', 'rootPath', 'features', 'template'];
    }

    public static function getAttributeLabels(): array
    {
        return [
            'moduleName' => 'Module name',
            'fields' => 'Fields',
            'rootPath' => 'Root path',
            'features' => 'Generators',
            'template' => 'Template',
        ];
    }

    public static function getHints(): array
    {
        return [
            'moduleName' => 'PascalCase plural, e.g. <code>Contracts</code> → namespace App\\Modules\\Contracts, entity Contract, table contracts.',
            'fields' => 'name:type[:modifier]* separated by commas, e.g. <code>title:string:required:max=255,amount:decimal:min=0,status:enum(draft|active|closed),due_on:date,owner_id:user</code>.',
            'features' => 'Generator ids (dependencies are added automatically).',
        ];
    }
}
