<?php

declare(strict_types=1);

namespace App\Platform\Workflow;

use App\Shared\Domain\Exception\ValidationException;

/**
 * Parsed, validated workflow definition (JSON stored in workflows.definition):
 *
 *   {
 *     "states": ["draft", "active", "archived"],            // or [{"code": "draft", "label": "Draft"}, …]
 *     "initial": "draft",
 *     "transitions": {
 *       "activate": {"from": ["draft"], "to": "active", "permission": "projects.update", "label": "Activate"},
 *       "archive":  {"from": ["active"], "to": "archived",
 *                    "approval": {"permission": "approvals.decide", "required_approvals": 2, "expires_hours": 72}}
 *     }
 *   }
 *
 * "from" may be "*" (any state). "permission" is optional (the calling module authorises entity access).
 */
final class WorkflowDefinition
{
    private const NAME = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param list<string> $states
     * @param array<string,string> $labels
     * @param array<string, array{from:list<string>, to:string, permission:?string, label:string, approval:?array{permission:string, required_approvals:int, expires_hours:?int}}> $transitions
     */
    private function __construct(
        public readonly array $states,
        public readonly array $labels,
        public readonly string $initial,
        public readonly array $transitions,
    ) {}

    /**
     * Full validation report without throwing.
     *
     * @return array{errors:list<string>, warnings:list<string>}
     */
    public static function analyse(mixed $definition): array
    {
        $errors = [];
        $warnings = [];
        if (is_string($definition)) {
            $definition = json_decode($definition, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['errors' => ['Invalid JSON: ' . json_last_error_msg()], 'warnings' => []];
            }
        }
        if (!is_array($definition)) {
            return ['errors' => ['The definition must be a JSON object.'], 'warnings' => []];
        }
        $states = [];
        foreach ((array) ($definition['states'] ?? []) as $s) {
            $code = is_array($s) ? (string) ($s['code'] ?? '') : (is_string($s) ? $s : '');
            if (preg_match(self::NAME, $code) !== 1) {
                $errors[] = 'Invalid state name "' . (is_scalar($code) ? $code : '?') . '" (lowercase letters, digits, "_").';
                continue;
            }
            if (in_array($code, $states, true)) {
                $errors[] = "Duplicate state \"$code\".";
                continue;
            }
            $states[] = $code;
        }
        if ($states === []) {
            $errors[] = 'At least one state is required ("states").';
        }
        $initial = (string) ($definition['initial'] ?? '');
        if ($initial === '') {
            $errors[] = 'The initial state is required ("initial").';
        } elseif (!in_array($initial, $states, true)) {
            $errors[] = "Initial state \"$initial\" is not a declared state.";
        }
        $transitions = $definition['transitions'] ?? [];
        if (!is_array($transitions) || ($transitions !== [] && array_is_list($transitions))) {
            $errors[] = '"transitions" must be an object {name: {from, to}}.';
            $transitions = [];
        }
        if ($transitions === []) {
            $warnings[] = 'The workflow has no transitions.';
        }
        $graph = [];
        foreach ($transitions as $name => $t) {
            if (!is_string($name) || preg_match(self::NAME, $name) !== 1) {
                $errors[] = 'Invalid transition name "' . $name . '".';
                continue;
            }
            if (!is_array($t)) {
                $errors[] = "Transition \"$name\" must be an object.";
                continue;
            }
            $from = $t['from'] ?? null;
            $from = $from === '*' ? $states : (is_string($from) ? [$from] : (is_array($from) ? $from : []));
            if ($from === []) {
                $errors[] = "Transition \"$name\": \"from\" is required.";
            }
            foreach ($from as $f) {
                if (!in_array($f, $states, true)) {
                    $errors[] = "Transition \"$name\": unknown source state \"" . (is_scalar($f) ? $f : '?') . '".';
                }
            }
            $to = (string) ($t['to'] ?? '');
            if (!in_array($to, $states, true)) {
                $errors[] = "Transition \"$name\": unknown target state \"$to\".";
            }
            if (isset($t['permission']) && (!is_string($t['permission']) || preg_match('/^[a-z0-9_.:-]{2,128}$/', $t['permission']) !== 1)) {
                $errors[] = "Transition \"$name\": invalid permission name.";
            }
            if (isset($t['approval'])) {
                $a = $t['approval'];
                if (!is_array($a) || !is_string($a['permission'] ?? null) || preg_match('/^[a-z0-9_.:-]{2,128}$/', $a['permission']) !== 1) {
                    $errors[] = "Transition \"$name\": approval.permission is required.";
                }
                $req = $a['required_approvals'] ?? 1;
                if (!is_int($req) || $req < 1 || $req > 10) {
                    $errors[] = "Transition \"$name\": approval.required_approvals must be 1–10.";
                }
                if (isset($a['expires_hours']) && (!is_int($a['expires_hours']) || $a['expires_hours'] < 1 || $a['expires_hours'] > 8760)) {
                    $errors[] = "Transition \"$name\": approval.expires_hours must be 1–8760.";
                }
            }
            foreach ($from as $f) {
                if (is_string($f) && in_array($f, $states, true) && in_array($to, $states, true)) {
                    $graph[$f][] = $to;
                    if ($f === $to) {
                        $warnings[] = "Transition \"$name\" does not change the state (\"$f\" → \"$to\").";
                    }
                }
            }
        }
        // reachability from the initial state
        if ($initial !== '' && in_array($initial, $states, true)) {
            $seen = [$initial => true];
            $queue = [$initial];
            while ($queue !== []) {
                $s = array_shift($queue);
                foreach ($graph[$s] ?? [] as $n) {
                    if (!isset($seen[$n])) {
                        $seen[$n] = true;
                        $queue[] = $n;
                    }
                }
            }
            foreach ($states as $s) {
                if (!isset($seen[$s])) {
                    $errors[] = "State \"$s\" is unreachable from the initial state \"$initial\".";
                }
            }
            $terminal = array_values(array_filter($states, static fn($s) => !isset($graph[$s])));
            if ($terminal === [] && $states !== []) {
                $warnings[] = 'The workflow has no terminal state (every state has outgoing transitions).';
            }
        }
        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /** @throws ValidationException */
    public static function parse(mixed $definition): self
    {
        $report = self::analyse($definition);
        if ($report['errors'] !== []) {
            throw new ValidationException(['definition' => $report['errors']], 'Invalid workflow definition: ' . $report['errors'][0]);
        }
        $d = is_string($definition) ? json_decode($definition, true) : $definition;
        $states = [];
        $labels = [];
        foreach ($d['states'] as $s) {
            $code = is_array($s) ? (string) $s['code'] : (string) $s;
            $states[] = $code;
            $labels[$code] = is_array($s) && isset($s['label']) ? (string) $s['label'] : ucfirst(str_replace('_', ' ', $code));
        }
        $transitions = [];
        foreach ($d['transitions'] ?? [] as $name => $t) {
            $from = $t['from'] === '*' ? $states : (is_string($t['from']) ? [$t['from']] : array_values($t['from']));
            $transitions[$name] = [
                'from' => $from,
                'to' => (string) $t['to'],
                'permission' => isset($t['permission']) ? (string) $t['permission'] : null,
                'label' => (string) ($t['label'] ?? ucfirst(str_replace('_', ' ', $name))),
                'approval' => isset($t['approval']) ? [
                    'permission' => (string) $t['approval']['permission'],
                    'required_approvals' => (int) ($t['approval']['required_approvals'] ?? 1),
                    'expires_hours' => isset($t['approval']['expires_hours']) ? (int) $t['approval']['expires_hours'] : null,
                ] : null,
            ];
        }
        return new self($states, $labels, (string) $d['initial'], $transitions);
    }

    /** @return list<string> transition names allowed from $state */
    public function transitionsFrom(string $state): array
    {
        return array_keys(array_filter($this->transitions, static fn($t) => in_array($state, $t['from'], true)));
    }

    public function label(string $state): string
    {
        return $this->labels[$state] ?? $state;
    }
}
