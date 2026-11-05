<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Installed theme packs: built-in ones from themes/<code>/{theme.json,theme.css} (shipped with the code) and
 * uploaded ones from table appearance_themes (shared by every instance). A built-in code cannot be overwritten.
 *
 * Author: ShokirjonMK
 */
final class ThemeRegistry
{
    public const DEFAULT = 'default';

    /** @var array<string,array>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ThemeValidator $validator,
        private readonly string $directory,
    ) {}

    public function directory(): string
    {
        return rtrim($this->directory, '/');
    }

    /** @return array<string, array{code:string,name:string,version:string,author:string,description:string,dark:bool,colors:array,builtin:bool,hash:string,created_at:?string}> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        foreach (glob($this->directory() . '/*/theme.json') ?: [] as $file) {
            try {
                $m = $this->validator->manifest((array) json_decode((string) file_get_contents($file), true));
            } catch (Throwable) {
                continue; // a broken pack on disk is skipped (reported by theme:list)
            }
            $cssFile = dirname($file) . '/theme.css';
            if ($m['code'] !== basename(dirname($file)) || !is_file($cssFile)) {
                continue;
            }
            $out[$m['code']] = $m + ['builtin' => true, 'hash' => substr(hash_file('sha256', $cssFile) ?: '', 0, 16), 'created_at' => null];
        }
        try {
            foreach ((new Query($this->db))->select(['code', 'manifest', 'hash', 'created_at'])->from('appearance_themes')->orderBy(['code' => SORT_ASC])->all() as $r) {
                if (isset($out[$r['code']])) {
                    continue;
                }
                $m = is_array($r['manifest']) ? $r['manifest'] : (json_decode((string) $r['manifest'], true) ?: []);
                $out[(string) $r['code']] = $m + ['code' => (string) $r['code'], 'name' => (string) $r['code'], 'version' => '1.0.0', 'author' => '', 'description' => '', 'dark' => true, 'colors' => []]
                    + ['builtin' => false, 'hash' => (string) $r['hash'], 'created_at' => (string) $r['created_at']];
            }
        } catch (Throwable) {
            // not migrated yet
        }
        uksort($out, static fn(string $a, string $b) => $a === self::DEFAULT ? -1 : ($b === self::DEFAULT ? 1 : strcmp($a, $b)));
        return $this->cache = $out;
    }

    public function get(string $code): ?array
    {
        return $this->all()[$code] ?? null;
    }

    public function exists(string $code): bool
    {
        return isset($this->all()[$code]);
    }

    public function css(string $code): ?string
    {
        $theme = $this->get($code);
        if ($theme === null) {
            return null;
        }
        if ($theme['builtin']) {
            $css = @file_get_contents($this->directory() . '/' . $code . '/theme.css');
            return $css === false ? null : $css;
        }
        $css = (new Query($this->db))->select('css')->from('appearance_themes')->where(['code' => $code])->scalar();
        return is_string($css) ? $css : null;
    }

    /** @param array $manifest validated */
    public function install(array $manifest, string $css, ?string $actorId, bool $replace = false): string
    {
        $code = $manifest['code'];
        $existing = $this->get($code);
        if ($existing !== null && $existing['builtin']) {
            throw new ConflictException(sprintf('"%s" is a built-in theme; choose another code.', $code), 'theme_builtin');
        }
        if ($existing !== null && !$replace) {
            throw new ConflictException(sprintf('Theme "%s" is already installed.', $code), 'theme_exists');
        }
        $hash = substr(hash('sha256', $css), 0, 16);
        $this->db->createCommand()->upsert('appearance_themes', [
            'code' => $code,
            'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'css' => $css,
            'hash' => $hash,
            'created_by' => $actorId,
        ])->execute();
        $this->cache = null;
        return $hash;
    }

    public function delete(string $code): void
    {
        $theme = $this->get($code) ?? throw new NotFoundException('Theme not found.');
        if ($theme['builtin']) {
            throw new ConflictException('Built-in themes cannot be deleted.', 'theme_builtin');
        }
        $this->db->createCommand()->delete('appearance_themes', ['code' => $code])->execute();
        $this->cache = null;
    }

    public function forget(): void
    {
        $this->cache = null;
    }
}
