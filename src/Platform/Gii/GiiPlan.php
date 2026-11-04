<?php

declare(strict_types=1);

namespace App\Platform\Gii;

/**
 * Result of a (dry) run: the planned files with their status and, after apply(), the write results.
 */
final class GiiPlan
{
    public bool $applied = false;

    /**
     * @param list<PlannedFile> $files
     * @param list<string> $generators generators that ran (dependencies included)
     */
    public function __construct(
        public readonly string $module,
        public readonly string $fields,
        public readonly array $generators,
        public readonly array $files,
        public readonly string $root,
    ) {}

    /** @return array<string,int> status => count */
    public function counts(): array
    {
        $c = [PlannedFile::NEW => 0, PlannedFile::OVERWRITE => 0, PlannedFile::SKIP => 0, PlannedFile::UNCHANGED => 0];
        foreach ($this->files as $f) {
            $c[$f->status]++;
        }
        return $c;
    }

    public function file(string $path): ?PlannedFile
    {
        foreach ($this->files as $f) {
            if ($f->path() === $path) {
                return $f;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn(PlannedFile $f) => $f->path(), $this->files);
    }

    public function hasErrors(): bool
    {
        foreach ($this->files as $f) {
            if ($f->error !== null) {
                return true;
            }
        }
        return false;
    }
}
