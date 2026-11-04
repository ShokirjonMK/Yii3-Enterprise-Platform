<?php

declare(strict_types=1);

namespace App\Platform\Monitoring;

/**
 * Parses docs/SECURITY_ASVS.md (OWASP ASVS 4.0.3 Level 2 checklist) into chapters and items so the
 * Security Center can render it. The Markdown file is the single source of truth (reviewed in MRs).
 *
 * Item rows: `| 2.1.1 | requirement | implemented|partial|n/a|planned | evidence |`
 */
final class AsvsChecklist
{
    public const STATUSES = ['implemented', 'partial', 'n/a', 'planned'];

    public function __construct(private readonly string $path) {}

    public static function default(): self
    {
        return new self(dirname(__DIR__, 3) . '/docs/SECURITY_ASVS.md');
    }

    /**
     * @return array{chapters:list<array{code:string, title:string, items:list<array{id:string, requirement:string, status:string, evidence:string}>}>, totals:array<string,int>, available:bool}
     */
    public function load(): array
    {
        $totals = array_fill_keys(self::STATUSES, 0);
        if (!is_file($this->path)) {
            return ['chapters' => [], 'totals' => $totals, 'available' => false];
        }
        $chapters = [];
        $current = null;
        foreach (file($this->path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^##\s+(V\d+)\s+(.+)$/', $line, $m) === 1) {
                if ($current !== null) {
                    $chapters[] = $current;
                }
                $current = ['code' => $m[1], 'title' => trim($m[2]), 'items' => []];
                continue;
            }
            if ($current === null || !str_starts_with(trim($line), '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim(trim($line), '|')));
            if (count($cells) < 4 || preg_match('/^\d+\.\d+\.\d+$/', $cells[0]) !== 1) {
                continue;
            }
            $status = self::normalize($cells[2]);
            $totals[$status]++;
            $current['items'][] = [
                'id' => $cells[0],
                'requirement' => self::plain($cells[1]),
                'status' => $status,
                'evidence' => self::plain(implode(' | ', array_slice($cells, 3))),
            ];
        }
        if ($current !== null) {
            $chapters[] = $current;
        }
        return ['chapters' => $chapters, 'totals' => $totals, 'available' => true];
    }

    private static function normalize(string $s): string
    {
        $s = strtolower(trim($s, " *`"));
        return match (true) {
            str_starts_with($s, 'implemented') => 'implemented',
            str_starts_with($s, 'partial') => 'partial',
            in_array($s, ['n/a', 'n.a.', 'na', 'not applicable'], true) => 'n/a',
            default => 'planned',
        };
    }

    /** Strips Markdown emphasis/code marks (the view HTML-encodes the result). */
    private static function plain(string $s): string
    {
        return trim(str_replace(['**', '`'], '', $s));
    }
}
