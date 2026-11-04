<?php

declare(strict_types=1);

/**
 * Template: lifecycle enum (src/Modules/<Module>/Domain/<Entity>Status.php).
 * Transitions are forward-only (any later state); the last state is terminal.
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$s = $m->statusField();
$cases = [];
$labels = [];
$transitions = [];
foreach ($s->options as $i => $o) {
    $case = $s->caseNames()[$i];
    $cases[] = "    case $case = '$o';";
    $labels[] = "            self::$case => " . var_export(ucfirst(str_replace('_', ' ', $o)), true) . ',';
    $later = array_slice($s->options, $i + 1);
    $transitions[] = "        '$o' => [" . implode(', ', array_map(static fn($x) => "'$x'", $later)) . '],';
}
$first = $s->caseNames()[0];
$last = $s->caseNames()[count($s->options) - 1];
$diagram = implode(' → ', $s->options);

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Domain;

use App\Shared\Domain\Exception\ConflictException;

/**
 * {{entityLabel}} lifecycle: __DIAGRAM__ (forward-only; "__LAST_VALUE__" is terminal and read-only).
 */
enum {{Entity}}Status: string
{
__CASES__

    private const TRANSITIONS = [
__TRANSITIONS__
    ];

    public static function initial(): self
    {
        return self::__FIRST__;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return array_map(static fn(string $s) => self::from($s), self::TRANSITIONS[$this->value]);
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$this->value], true);
    }

    public function assertTransitionTo(self $to): void
    {
        if (!$this->canTransitionTo($to)) {
            throw new ConflictException(
                sprintf('A {{entityLabelLower}} cannot move from "%s" to "%s".', $this->value, $to->value),
                'invalid_transition',
            );
        }
    }

    public function isTerminal(): bool
    {
        return $this === self::__LAST__;
    }

    public function label(): string
    {
        return match ($this) {
__LABELS__
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $s) => $s->value, self::cases());
    }
}

PHP, [
    '__CASES__' => implode("\n", $cases),
    '__TRANSITIONS__' => implode("\n", $transitions),
    '__LABELS__' => implode("\n", $labels),
    '__FIRST__' => $first,
    '__LAST__' => $last,
    '__LAST_VALUE__' => $s->options[count($s->options) - 1],
    '__DIAGRAM__' => $diagram,
]);
