<?php

declare(strict_types=1);

namespace App\Platform\Gii\Generator;

use App\Platform\Gii\PathGuard;
use App\Platform\Gii\Spec\GiiSpecException;
use App\Platform\Gii\Spec\ModuleSpec;
use InvalidArgumentException;
use Yiisoft\Yii\Gii\Component\CodeFile\CodeFile;
use Yiisoft\Yii\Gii\Generator\AbstractGenerator;
use Yiisoft\Yii\Gii\GeneratorCommandInterface;

/**
 * Base of every Enterprise Gii generator. Built on yii-gii:
 *  - {@see AbstractGenerator::generate()} validates the {@see ModuleCommand} and calls doGenerate(),
 *  - {@see AbstractGenerator::render()} renders the PHP templates from src/Platform/Gii/templates/<id>/,
 *  - results are yii-gii {@see CodeFile}s (state new / same / different, unified diff, save()).
 *
 * Subclasses only declare which files they produce ({@see files()}).
 */
abstract class AbstractEnterpriseGenerator extends AbstractGenerator
{
    public static function getCommandClass(): string
    {
        return ModuleCommand::class;
    }

    /** @return list<string> generator ids this generator needs (resolved automatically) */
    public static function requires(): array
    {
        return [];
    }

    /** Relative path whose presence means "this generator's code already exists" (feature marker). */
    public static function marker(ModuleSpec $m): ?string
    {
        return null;
    }

    /** Throws when the field spec does not support this generator. */
    public static function assertApplicable(ModuleSpec $m): void
    {
    }

    public function getTemplatePath(GeneratorCommandInterface $command): string
    {
        return dirname(__DIR__) . '/templates/' . static::getId();
    }

    /**
     * @return array<string, string|array{0:string, 1:array<string,mixed>}> relative path => template file (or [template, params])
     */
    abstract protected function files(ModuleSpec $m): array;

    final protected function doGenerate(GeneratorCommandInterface $command): array
    {
        if (!$command instanceof ModuleCommand) {
            throw new InvalidArgumentException('Enterprise Gii generators expect a ' . ModuleCommand::class . '.');
        }
        $m = $command->spec();
        static::assertApplicable($m);
        $out = [];
        foreach ($this->files($m) as $rel => $def) {
            [$template, $params] = is_array($def) ? $def : [$def, []];
            $path = PathGuard::resolve($m->root, $rel);
            $content = $this->render($command, $template, ['m' => $m] + $params);
            $file = (new CodeFile($path, $content))->withBasePath($m->root);
            $out[$file->getId()] = $file;
        }
        return $out;
    }

    protected static function notApplicable(string $message): never
    {
        throw new GiiSpecException(['generators' => [$message]]);
    }
}
