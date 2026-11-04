<?php

declare(strict_types=1);

namespace App\Platform\Gii;

use Yiisoft\Yii\Gii\Component\CodeFile\CodeFile;
use Yiisoft\Yii\Gii\Component\CodeFile\CodeFileStateEnum;
use Yiisoft\Yii\Gii\Component\CodeFile\CodeFileWriteOperationEnum;

/**
 * One generated file in a plan: the yii-gii CodeFile plus the decision taken for it.
 *
 *   new        file does not exist → will be created
 *   unchanged  identical content exists → skipped (yii-gii PRESENT_SAME)
 *   skip       different content exists and overwrite was NOT requested → protected, left as is
 *   overwrite  different content exists and the path was explicitly listed in --overwrite / ticked
 */
final class PlannedFile
{
    public const NEW = 'new';
    public const UNCHANGED = 'unchanged';
    public const SKIP = 'skip';
    public const OVERWRITE = 'overwrite';

    public ?string $result = null;
    public ?string $error = null;

    public function __construct(
        public readonly CodeFile $file,
        public readonly string $generator,
        public readonly string $status,
    ) {}

    public static function decide(CodeFile $file, string $generator, bool $overwriteRequested): self
    {
        $status = match ($file->getState()) {
            CodeFileStateEnum::NOT_EXIST => self::NEW,
            CodeFileStateEnum::PRESENT_SAME => self::UNCHANGED,
            CodeFileStateEnum::PRESENT_DIFFERENT => $overwriteRequested ? self::OVERWRITE : self::SKIP,
        };
        return new self($file, $generator, $status);
    }

    public function path(): string
    {
        return str_replace('\\', '/', $this->file->getRelativePath());
    }

    /** yii-gii CodeFileWriter answer for this file. */
    public function operation(): string
    {
        return in_array($this->status, [self::NEW, self::OVERWRITE], true)
            ? CodeFileWriteOperationEnum::SAVE->value
            : CodeFileWriteOperationEnum::SKIP->value;
    }

    /** Unified diff against the existing file (yii-gii CodeFile::diff, phpspec/php-diff). */
    public function diff(): string
    {
        $d = $this->file->diff();
        return is_string($d) ? $d : '';
    }

    public function content(): string
    {
        return $this->file->getContent();
    }

    public function exists(): bool
    {
        return $this->file->getState() !== CodeFileStateEnum::NOT_EXIST;
    }
}
