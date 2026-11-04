<?php

declare(strict_types=1);

namespace App\Platform\Gii;

use App\Platform\Gii\Generator\AbstractEnterpriseGenerator;
use App\Platform\Gii\Generator\AdminUiGenerator;
use App\Platform\Gii\Generator\CrudGenerator;
use App\Platform\Gii\Generator\EnterpriseModuleGenerator;
use App\Platform\Gii\Generator\EntityGenerator;
use App\Platform\Gii\Generator\IntegrationConnectorGenerator;
use App\Platform\Gii\Generator\KafkaEventGenerator;
use App\Platform\Gii\Generator\ModuleCommand;
use App\Platform\Gii\Generator\NotificationGenerator;
use App\Platform\Gii\Generator\RbacGenerator;
use App\Platform\Gii\Generator\ReportGenerator;
use App\Platform\Gii\Generator\RestApiGenerator;
use App\Platform\Gii\Generator\SchedulerGenerator;
use App\Platform\Gii\Generator\TestsGenerator;
use App\Platform\Gii\Generator\WebUiGenerator;
use App\Platform\Gii\Generator\WorkerJobGenerator;
use App\Platform\Gii\Generator\WorkflowGenerator;
use App\Platform\Gii\Spec\FieldSpecParser;
use App\Platform\Gii\Spec\GiiSpecException;
use App\Platform\Gii\Spec\ModuleSpec;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Validator\RuleHandlerResolver\SimpleRuleHandlerContainer;
use Yiisoft\Validator\Validator;
use Yiisoft\Yii\Gii\Validator\TemplateRuleHandler;
use Yiisoft\Yii\Gii\Component\CodeFile\CodeFileWriter;
use Yiisoft\Yii\Gii\Exception\InvalidGeneratorCommandException;
use Yiisoft\Yii\Gii\Gii;
use Yiisoft\Yii\Gii\GiiInterface;
use Yiisoft\Yii\Gii\ParametersProvider;

/**
 * Enterprise Gii facade used by `./yii gii:module` and Admin → Developer Tools → Enterprise Gii.
 *
 *   $plan = $gii->plan('Contracts', 'title:string:required,…', 'all', overwrite: []);   // dry run, writes nothing
 *   $gii->apply($plan);                                                                // writes new + approved files
 *
 * Generators are registered in a yii-gii {@see Gii} registry; files are yii-gii CodeFiles written by
 * yii-gii's {@see CodeFileWriter}. Existing files that differ are never overwritten unless their relative
 * path is listed in $overwrite; identical files are skipped. Every path passes {@see PathGuard}.
 */
final class EnterpriseGii
{
    /** Run order (also the order of `all`). */
    public const GENERATORS = [
        'entity' => EntityGenerator::class,
        'crud' => CrudGenerator::class,
        'rbac' => RbacGenerator::class,
        'event' => KafkaEventGenerator::class,
        'workflow' => WorkflowGenerator::class,
        'notification' => NotificationGenerator::class,
        'job' => WorkerJobGenerator::class,
        'report' => ReportGenerator::class,
        'scheduler' => SchedulerGenerator::class,
        'connector' => IntegrationConnectorGenerator::class,
        'admin' => AdminUiGenerator::class,
        'web' => WebUiGenerator::class,
        'api' => RestApiGenerator::class,
        'tests' => TestsGenerator::class,
    ];

    private ?GiiInterface $gii = null;

    public function __construct(
        private readonly Aliases $aliases,
        private readonly ParametersProvider $parameters,
        private readonly string $rootPath,
        private readonly CodeFileWriter $writer = new CodeFileWriter(),
    ) {}

    /** yii-gii is a dev dependency; production images do not ship it. */
    public static function isInstalled(): bool
    {
        return class_exists(Gii::class) && class_exists(CodeFileWriter::class);
    }

    /** Same service writing into another root (tests use a temporary copy). */
    public function withRoot(string $rootPath): self
    {
        return new self($this->aliases, $this->parameters, $rootPath, $this->writer);
    }

    public function root(): string
    {
        return $this->rootPath;
    }

    /** yii-gii registry with all Enterprise Gii generators (composite "module" included). */
    public function gii(): GiiInterface
    {
        if ($this->gii !== null) {
            return $this->gii;
        }
        $gii = new Gii([], []);
        // yii-gii's TemplateRule handler needs this registry (the app validator resolves handlers without DI)
        $validator = new Validator(new SimpleRuleHandlerContainer([
            TemplateRuleHandler::class => new TemplateRuleHandler($this->aliases, $gii, $this->parameters),
        ]));
        $subs = [];
        foreach (self::GENERATORS as $id => $class) {
            $subs[$id] = new $class($this->aliases, $validator, $this->parameters);
            $gii->addGenerator($subs[$id]);
        }
        $gii->addGenerator(new EnterpriseModuleGenerator($this->aliases, $validator, $this->parameters, $subs));
        return $this->gii = $gii;
    }

    /** @return list<array{id:string, name:string, description:string, requires:list<string>}> */
    public function catalog(): array
    {
        $out = [];
        foreach (['module' => EnterpriseModuleGenerator::class] + self::GENERATORS as $id => $class) {
            $out[] = ['id' => $id, 'name' => $class::getName(), 'description' => $class::getDescription(), 'requires' => $id === 'module' ? [] : $class::requires()];
        }
        return $out;
    }

    /**
     * Expands "all" / "module" and dependencies; returns ids in run order.
     *
     * @param string|list<string> $generators
     * @return list<string>
     */
    public function resolve(string|array $generators): array
    {
        $ids = is_string($generators) ? preg_split('/[\s,]+/', trim($generators)) ?: [] : $generators;
        $ids = array_values(array_filter(array_map('trim', $ids), static fn($s) => $s !== ''));
        if ($ids === [] || in_array('all', $ids, true) || in_array('module', $ids, true)) {
            return array_keys(self::GENERATORS);
        }
        $unknown = array_diff($ids, array_keys(self::GENERATORS));
        if ($unknown !== []) {
            throw new GiiSpecException(['generators' => [sprintf('Unknown generator(s): %s. Known: all, %s.', implode(', ', $unknown), implode(', ', array_keys(self::GENERATORS)))]]);
        }
        $want = [];
        $add = static function (string $id) use (&$add, &$want): void {
            if (isset($want[$id])) {
                return;
            }
            $want[$id] = true;
            foreach (self::GENERATORS[$id]::requires() as $dep) {
                $add($dep);
            }
        };
        foreach ($ids as $id) {
            $add($id);
        }
        return array_values(array_filter(array_keys(self::GENERATORS), static fn($id) => isset($want[$id])));
    }

    /**
     * Dry run: renders every file and decides new / unchanged / skip / overwrite. Writes nothing.
     *
     * @param string|list<string> $generators
     * @param list<string> $overwrite relative paths that may be overwritten
     * @throws GiiSpecException
     */
    public function plan(string $module, string $fields, string|array $generators = 'all', array $overwrite = []): GiiPlan
    {
        $module = trim($module);
        $errors = [];
        if (preg_match(ModuleSpec::NAME, $module) !== 1) {
            $errors['module'][] = 'The module name must match ^[A-Z][A-Za-z0-9]{2,40}$ (PascalCase, e.g. Contracts).';
        } elseif (in_array($module, ModuleSpec::RESERVED, true)) {
            $errors['module'][] = sprintf('"%s" is reserved (platform section, route or the reference module).', $module);
        }
        try {
            $parsed = FieldSpecParser::parse($fields);
        } catch (GiiSpecException $e) {
            $errors += $e->errors();
            $parsed = [];
        }
        try {
            $selected = $this->resolve($generators);
        } catch (GiiSpecException $e) {
            $errors += $e->errors();
            $selected = [];
        }
        if ($errors !== []) {
            throw new GiiSpecException($errors);
        }
        $statusless = !array_filter($parsed, static fn($f) => $f->isStatus());
        if ($statusless) {
            // the workflow generator needs a lifecycle; with "all" it is simply left out
            $explicit = is_array($generators) ? $generators : preg_split('/[\s,]+/', $generators);
            if (in_array('workflow', (array) $explicit, true)) {
                throw new GiiSpecException(['generators' => ['The workflow generator needs a lifecycle field: status:enum(a|b|...).']]);
            }
            $selected = array_values(array_diff($selected, ['workflow']));
        }

        // features = generators being run + generators whose code already exists (template switches)
        $probe = new ModuleSpec($module, $parsed, $selected, $this->rootPath);
        $features = $selected;
        foreach (self::GENERATORS as $id => $class) {
            /** @var class-string<AbstractEnterpriseGenerator> $class */
            $marker = $class::marker($probe);
            if (!in_array($id, $features, true) && $marker !== null && is_file($this->rootPath . '/' . $marker)) {
                $features[] = $id;
            }
        }
        $features = array_values(array_filter(array_keys(self::GENERATORS), static fn($id) => in_array($id, $features, true)));

        $command = new ModuleCommand($module, $fields, $this->rootPath, $features, $selected);
        /** @var EnterpriseModuleGenerator $composite */
        $composite = $this->gii()->getGenerator('module');
        try {
            $generated = $composite->generateAll($command);
        } catch (InvalidGeneratorCommandException $e) {
            $msgs = [];
            foreach ($e->getResult()->getErrorMessagesIndexedByPath() as $path => $list) {
                foreach ($list as $msg) {
                    $msgs[$path === 'moduleName' ? 'module' : (string) $path][] = $msg;
                }
            }
            throw new GiiSpecException($msgs ?: ['module' => ['Invalid generator input.']]);
        }

        $overwrite = array_values(array_unique(array_map(static fn($p) => ltrim(str_replace('\\', '/', trim((string) $p)), '/'), $overwrite)));
        $files = [];
        foreach ($generated as [$generatorId, $file]) {
            $rel = str_replace('\\', '/', $file->getRelativePath());
            PathGuard::resolve($this->rootPath, $rel);
            $files[] = PlannedFile::decide($file, $generatorId, in_array($rel, $overwrite, true));
        }
        $known = array_map(static fn(PlannedFile $f) => $f->path(), $files);
        $foreign = array_diff(array_filter($overwrite), $known);
        if ($foreign !== []) {
            throw new GiiSpecException(['overwrite' => [sprintf('Not part of this generation: %s.', implode(', ', $foreign))]]);
        }
        return new GiiPlan($module, $fields, $selected, $files, $this->rootPath);
    }

    /** Writes the plan through yii-gii's CodeFileWriter (new + explicitly approved overwrites only). */
    public function apply(GiiPlan $plan): GiiPlan
    {
        $codeFiles = [];
        $answers = [];
        foreach ($plan->files as $f) {
            // re-check right before writing (the tree may have changed since the preview)
            $abs = PathGuard::resolve($plan->root, $f->path());
            $codeFiles[$f->file->getId()] = $f->file;
            $answers[$f->file->getId()] = $f->operation();
            if ($f->status === PlannedFile::NEW && file_exists($abs)) {
                // appeared after the preview: never overwrite silently
                $answers[$f->file->getId()] = 'skip';
            }
        }
        $results = $this->writer->write($codeFiles, $answers)->getResults();
        foreach ($plan->files as $f) {
            $r = $results[$f->file->getId()] ?? null;
            $f->result = $r['status'] ?? null;
            $f->error = $r['error'] ?? null;
        }
        $plan->applied = true;
        return $plan;
    }
}
