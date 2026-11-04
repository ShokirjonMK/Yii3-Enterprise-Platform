<?php

declare(strict_types=1);

namespace App\Modules\Projects\Domain;

use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Domain\Uuid;
use DateTimeImmutable;

/**
 * Input normalisation + validation of project attributes (pure, no I/O).
 * Unknown keys are ignored; only keys present in the input are validated on partial updates.
 */
final class ProjectRules
{
    public const EDITABLE = ['code', 'name', 'description', 'priority', 'owner_id', 'budget', 'starts_on', 'due_on', 'tags'];
    public const MAX_TAGS = 10;
    public const MAX_BUDGET = '999999999999.99';

    /**
     * @return array<string,mixed> normalised values (DB column names)
     * @throws ValidationException
     */
    public static function normalize(array $in, bool $creating): array
    {
        $errors = [];
        $out = [];
        $has = static fn(string $k): bool => array_key_exists($k, $in);
        $str = static fn(string $k): string => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';

        if ($creating || $has('code')) {
            $code = strtoupper($str('code'));
            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,31}$/', $code)) {
                $errors['code'][] = 'Code must be 2–32 characters: letters, digits, "-" or "_".';
            }
            $out['code'] = $code;
        }
        if ($creating || $has('name')) {
            $name = preg_replace('/\s+/u', ' ', $str('name')) ?? '';
            $len = mb_strlen($name);
            if ($len < 2 || $len > 200) {
                $errors['name'][] = 'Name must be between 2 and 200 characters.';
            }
            $out['name'] = $name;
        }
        if ($has('description')) {
            $d = is_scalar($in['description']) ? trim((string) $in['description']) : '';
            if (mb_strlen($d) > 5000) {
                $errors['description'][] = 'Description must not exceed 5000 characters.';
            }
            $out['description'] = $d;
        } elseif ($creating) {
            $out['description'] = '';
        }
        if ($creating || $has('priority')) {
            $p = $str('priority') ?: ProjectPriority::Normal->value;
            if (ProjectPriority::tryFrom($p) === null) {
                $errors['priority'][] = 'Priority must be one of: ' . implode(', ', ProjectPriority::values()) . '.';
            }
            $out['priority'] = $p;
        }
        if ($has('owner_id')) {
            $o = $str('owner_id');
            if ($o !== '' && !Uuid::isValid($o)) {
                $errors['owner_id'][] = 'Owner must be a valid user id.';
            }
            $out['owner_id'] = $o === '' ? null : strtolower($o);
        }
        if ($has('budget')) {
            $raw = $in['budget'];
            if ($raw === null || $raw === '') {
                $out['budget'] = null;
            } else {
                $b = is_float($raw) || is_int($raw) ? number_format((float) $raw, 2, '.', '') : str_replace([' ', ','], ['', '.'], trim((string) $raw));
                if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $b)) {
                    $errors['budget'][] = 'Budget must be a non-negative amount with at most 2 decimals.';
                } else {
                    $out['budget'] = number_format((float) $b, 2, '.', '');
                }
            }
        }
        foreach (['starts_on', 'due_on'] as $k) {
            if ($has($k)) {
                $v = $str($k);
                if ($v === '') {
                    $out[$k] = null;
                    continue;
                }
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                if ($d === false || $d->format('Y-m-d') !== $v) {
                    $errors[$k][] = 'Use the date format YYYY-MM-DD.';
                } else {
                    $out[$k] = $v;
                }
            }
        }
        if ($has('tags')) {
            $tags = $in['tags'];
            if (is_string($tags)) {
                $tags = $tags === '' ? [] : explode(',', $tags);
            }
            if (!is_array($tags)) {
                $errors['tags'][] = 'Tags must be a list of strings.';
                $tags = [];
            }
            $clean = [];
            foreach ($tags as $t) {
                $t = is_scalar($t) ? mb_strtolower(trim((string) $t)) : '';
                if ($t === '') {
                    continue;
                }
                if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $t)) {
                    $errors['tags'][] = sprintf('Invalid tag "%s": use up to 32 lowercase letters, digits, "-" or "_".', mb_substr($t, 0, 40));
                    continue;
                }
                $clean[$t] = true;
            }
            if (count($clean) > self::MAX_TAGS) {
                $errors['tags'][] = sprintf('At most %d tags are allowed.', self::MAX_TAGS);
            }
            $out['tags'] = array_keys($clean);
        } elseif ($creating) {
            $out['tags'] = [];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $out;
    }

    /** Cross-field invariant on the resulting state. */
    public static function assertDates(?string $startsOn, ?string $dueOn): void
    {
        if ($startsOn !== null && $dueOn !== null && $dueOn < $startsOn) {
            throw ValidationException::single('due_on', 'The due date cannot be before the start date.');
        }
    }
}
