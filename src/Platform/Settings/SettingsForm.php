<?php

declare(strict_types=1);

namespace App\Platform\Settings;

use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ValidationException;

/**
 * Edits a fixed list of settings on a section's own page (e.g. Admin → 11 Kafka → Settings) with the
 * section's permission. Field names are the setting keys with "." replaced by "_". Secrets: empty input
 * keeps the stored value, "clear[]" removes it. Values set by an environment variable are read-only.
 *
 * Author: ShokirjonMK
 */
final class SettingsForm
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param list<string> $keys
     * @return list<array{key:string, name:string, label:string, type:string, options:list<string>, min:?int, max:?int, value:mixed, is_set:bool, source:string, critical:bool}>
     */
    public function fields(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $def = $this->settings->definition($key);
            $value = $this->settings->get($key);
            $out[] = [
                'key' => $key,
                'name' => str_replace('.', '_', $key),
                'label' => (string) $def['label'],
                'type' => (string) $def['type'],
                'options' => array_map('strval', $def['options'] ?? []),
                'min' => $def['min'] ?? null,
                'max' => $def['max'] ?? null,
                'value' => $def['type'] === 'secret' ? null : $value,
                'is_set' => $value !== null && $value !== '',
                'source' => $this->settings->source($key),
                'critical' => (bool) ($def['critical'] ?? false),
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $keys
     * @param array<string,mixed> $input
     * @return list<string> changed keys
     */
    public function save(string $actorId, array $keys, array $input, string $permission, string $comment = ''): array
    {
        $changed = [];
        $errors = [];
        $clear = (array) ($input['clear'] ?? []);
        foreach ($keys as $key) {
            $def = $this->settings->definition($key);
            $field = str_replace('.', '_', $key);
            if ($this->settings->source($key) === 'env') {
                continue; // controlled by the environment
            }
            try {
                if ($def['type'] === 'secret') {
                    if (in_array($key, $clear, true)) {
                        $this->settings->reset($actorId, $key, permission: $permission);
                        $changed[] = $key;
                    } elseif (trim((string) ($input[$field] ?? '')) !== '') {
                        $this->settings->set($actorId, $key, trim((string) $input[$field]), comment: $comment, permission: $permission);
                        $changed[] = $key;
                    }
                    continue;
                }
                if (!array_key_exists($field, $input)) {
                    continue;
                }
                $new = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
                $current = $this->settings->get($key);
                if ($def['type'] === 'json') {
                    $decoded = is_string($new) ? json_decode($new, true) : $new;
                    if (json_last_error() === JSON_ERROR_NONE && $decoded === $current) {
                        continue;
                    }
                    $this->settings->set($actorId, $key, $new, comment: $comment, permission: $permission);
                    $changed[] = $key;
                    continue;
                }
                if ((string) $new !== (string) ($current ?? '')) {
                    $this->settings->set($actorId, $key, $def['type'] === 'int' ? $new : (string) $new, comment: $comment, permission: $permission);
                    $changed[] = $key;
                }
            } catch (ValidationException $e) {
                foreach ($e->errors() as $msgs) {
                    $errors[$field] = $msgs;
                }
            } catch (AccessDeniedException $e) {
                $errors[$field] = [$e->getMessage()];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $changed;
    }
}
