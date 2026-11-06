<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use App\Platform\Access\AccessService;
use App\Platform\Audit\AuditLogger;
use App\Shared\Domain\Exception\AccessDeniedException;
use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use RuntimeException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use ZipArchive;

/**
 * Which theme is shown and how themes are installed.
 *
 * Resolution: admin preview (own session) → user choice (when allowed) → tenant theme → global theme → "default".
 * A choice that points to a removed theme falls back to the next level. Global/tenant changes and installs need
 * settings.manage (checked here and on the route) and are audited; a user's own choice is audited as info.
 *
 * Author: ShokirjonMK
 */
final class ThemeService
{
    /** @var array<string,string>|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly ThemeValidator $validator,
        private readonly ConnectionInterface $db,
        private readonly AccessService $access,
        private readonly AuditLogger $audit,
    ) {}

    public function registry(): ThemeRegistry
    {
        return $this->registry;
    }

    /** Active theme code for a request context. */
    public function resolve(?string $tenantId, ?string $userId, ?string $preview = null): string
    {
        if ($preview !== null && $this->registry->exists($preview)) {
            return $preview;
        }
        $key = ($tenantId ?? '') . '|' . ($userId ?? '');
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }
        $rows = [];
        try {
            $q = (new Query($this->db))->select(['scope', 'scope_id', 'theme', 'allow_user_choice'])->from('appearance_choice')
                ->where(['scope' => 'global', 'scope_id' => '']);
            if ($tenantId !== null) {
                $q->orWhere(['scope' => 'tenant', 'scope_id' => $tenantId]);
            }
            if ($userId !== null) {
                $q->orWhere(['scope' => 'user', 'scope_id' => $userId]);
            }
            foreach ($q->all() as $r) {
                $rows[$r['scope']] = $r;
            }
        } catch (Throwable) {
            // not migrated yet → default
        }
        $allowUser = (bool) ($rows['global']['allow_user_choice'] ?? true);
        $code = ThemeRegistry::DEFAULT;
        foreach ([$allowUser ? ($rows['user'] ?? null) : null, $rows['tenant'] ?? null, $rows['global'] ?? null] as $row) {
            if ($row !== null && $this->registry->exists((string) $row['theme'])) {
                $code = (string) $row['theme'];
                break;
            }
        }
        return $this->resolved[$key] = $code;
    }

    /** @return array{theme:string, allow_user_choice:bool, tenants:array<string,string>} */
    public function settings(): array
    {
        $out = ['theme' => ThemeRegistry::DEFAULT, 'allow_user_choice' => true, 'tenants' => []];
        foreach ((new Query($this->db))->from('appearance_choice')->where(['scope' => ['global', 'tenant']])->all() as $r) {
            if ($r['scope'] === 'global') {
                $out['theme'] = (string) $r['theme'];
                $out['allow_user_choice'] = (bool) $r['allow_user_choice'];
            } else {
                $out['tenants'][(string) $r['scope_id']] = (string) $r['theme'];
            }
        }
        return $out;
    }

    public function userChoice(string $userId): ?string
    {
        $v = (new Query($this->db))->select('theme')->from('appearance_choice')->where(['scope' => 'user', 'scope_id' => $userId])->scalar();
        return is_string($v) ? $v : null;
    }

    public function userChoiceAllowed(): bool
    {
        return $this->settings()['allow_user_choice'];
    }

    /** Global (scopeId null) or tenant theme; $code null removes a tenant override. */
    public function activate(?string $actorId, ?string $code, string $scope = 'global', ?string $scopeId = null): void
    {
        if ($actorId !== null) {
            $this->access->assert($actorId, 'settings.manage');
        }
        if (!in_array($scope, ['global', 'tenant'], true) || ($scope === 'tenant') !== ($scopeId !== null)) {
            throw ValidationException::single('scope', 'Invalid scope.');
        }
        if ($scope === 'tenant' && preg_match('/^[0-9a-f-]{36}$/', (string) $scopeId) !== 1) {
            throw new NotFoundException('Tenant not found.');
        }
        if ($code !== null && !$this->registry->exists($code)) {
            throw new NotFoundException('Theme not found.');
        }
        $before = $this->settings();
        if ($code === null) {
            if ($scope === 'global') {
                throw ValidationException::single('theme', 'Choose a theme.');
            }
            $this->db->createCommand()->delete('appearance_choice', ['scope' => 'tenant', 'scope_id' => $scopeId])->execute();
        } else {
            $this->db->createCommand()->upsert('appearance_choice', [
                'scope' => $scope, 'scope_id' => $scopeId ?? '', 'theme' => $code,
                'allow_user_choice' => $scope === 'global' ? $before['allow_user_choice'] : true,
                'updated_at' => new Expression('now()'), 'updated_by' => $actorId,
            ])->execute();
        }
        $this->resolved = null;
        $this->audit->record(
            'appearance.theme.activated',
            'appearance',
            $scope . ($scopeId ? ':' . $scopeId : ''),
            ['theme' => $scope === 'global' ? $before['theme'] : ($before['tenants'][$scopeId] ?? null)],
            ['theme' => $code],
            category: 'config',
            severity: 'notice',
            actorId: $actorId,
        );
    }

    public function allowUserChoice(?string $actorId, bool $allow): void
    {
        if ($actorId !== null) {
            $this->access->assert($actorId, 'settings.manage');
        }
        $this->db->createCommand()->update('appearance_choice', ['allow_user_choice' => $allow, 'updated_at' => new Expression('now()'), 'updated_by' => $actorId], ['scope' => 'global', 'scope_id' => ''])->execute();
        $this->resolved = null;
        $this->audit->record('appearance.user_choice', 'appearance', 'global', null, ['allow_user_choice' => $allow], category: 'config', severity: 'info', actorId: $actorId);
    }

    /** A user's own theme; null = follow the organisation's theme. */
    public function chooseForUser(string $userId, ?string $code): void
    {
        if ($code !== null) {
            if (!$this->userChoiceAllowed()) {
                throw new AccessDeniedException('Choosing a personal theme is switched off by the administrator.');
            }
            if (!$this->registry->exists($code)) {
                throw new NotFoundException('Theme not found.');
            }
            $this->db->createCommand()->upsert('appearance_choice', ['scope' => 'user', 'scope_id' => $userId, 'theme' => $code, 'updated_at' => new Expression('now()'), 'updated_by' => $userId])->execute();
        } else {
            $this->db->createCommand()->delete('appearance_choice', ['scope' => 'user', 'scope_id' => $userId])->execute();
        }
        $this->resolved = null;
        $this->audit->record('appearance.user_theme', 'user', $userId, null, ['theme' => $code], category: 'config', severity: 'info', actorId: $userId);
    }

    /** Installs a validated .zip pack. @return array manifest */
    public function installZip(?string $actorId, string $path, bool $replace = false): array
    {
        if ($actorId !== null) {
            $this->access->assert($actorId, 'settings.manage');
        }
        [$manifest, $css] = $this->validator->zip($path);
        $hash = $this->registry->install($manifest, $css, $actorId, $replace);
        $this->audit->record(
            'appearance.theme.installed',
            'theme',
            $manifest['code'],
            null,
            ['version' => $manifest['version'], 'hash' => $hash],
            category: 'config',
            severity: 'notice',
            actorId: $actorId,
        );
        return $manifest;
    }

    public function delete(?string $actorId, string $code): void
    {
        if ($actorId !== null) {
            $this->access->assert($actorId, 'settings.manage');
        }
        $s = $this->settings();
        if ($s['theme'] === $code) {
            throw new ConflictException('This theme is active. Activate another theme first.', 'theme_active');
        }
        $this->registry->delete($code);
        // tenants and users that used it fall back automatically; clean their rows
        $this->db->createCommand()->delete('appearance_choice', ['theme' => $code])->execute();
        $this->resolved = null;
        $this->audit->record('appearance.theme.deleted', 'theme', $code, null, null, category: 'config', severity: 'notice', actorId: $actorId);
    }

    /** Zip bytes of a theme (starter template to customise). */
    public function exportZip(string $code, ?string $newCode = null): string
    {
        $theme = $this->registry->get($code) ?? throw new NotFoundException('Theme not found.');
        $css = (string) $this->registry->css($code);
        $manifest = array_intersect_key($theme, array_flip(['code', 'name', 'version', 'author', 'description', 'dark', 'colors']));
        if ($newCode !== null) {
            $manifest['code'] = $newCode;
            $manifest['name'] = 'My theme';
            $manifest['version'] = '1.0.0';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'theme');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $folder = $manifest['code'] . '/';
        $zip->addFromString($folder . 'theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $zip->addFromString($folder . 'theme.css', $css);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /** CLI scaffold: themes/<code>/ copied from another theme. @return string created directory */
    public function scaffold(string $code, string $from = ThemeRegistry::DEFAULT, ?string $name = null): string
    {
        if (preg_match(ThemeValidator::CODE, $code) !== 1) {
            throw ValidationException::single('code', 'Theme code must be 2–40 characters: lowercase letters, digits and "-".');
        }
        if ($this->registry->exists($code)) {
            throw new ConflictException(sprintf('Theme "%s" already exists.', $code), 'theme_exists');
        }
        $source = $this->registry->get($from) ?? throw new NotFoundException(sprintf('Source theme "%s" not found.', $from));
        $dir = $this->registry->directory() . '/' . $code;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir");
        }
        $manifest = ['code' => $code, 'name' => $name ?? ucwords(str_replace('-', ' ', $code)), 'version' => '1.0.0', 'author' => '',
            'description' => 'Based on ' . $source['name'], 'dark' => true, 'colors' => $source['colors']];
        file_put_contents($dir . '/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/theme.css', (string) $this->registry->css($from));
        $this->registry->forget();
        return $dir;
    }
}
