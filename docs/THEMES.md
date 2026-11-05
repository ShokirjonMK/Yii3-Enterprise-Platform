# Design themes

Author: ShokirjonMK

The whole UI (admin panel, end-user web, sign-in pages) is built on CSS design tokens (custom properties in
`assets/admin/app.css`). A **theme pack** only overrides those tokens, so it cannot break layouts and works in
light and dark mode.

## Use a ready-made theme (1 click)

**Admin → System Settings → Appearance** (`/admin/settings/appearance`):

- **Preview**: only your own session sees the theme. A banner lets you stop the preview.
- **Activate**: applies the theme to everyone. Needs `settings.manage` and step-up re-authentication, and is audited.
- **Tenant themes**: gives one tenant its own theme. Other tenants keep the global one.
- **Personal themes**: when allowed, users pick a theme in **Account → Appearance** (`/account/appearance`).

Which theme is shown: admin preview → user's choice (if allowed) → tenant theme → global theme → `default`.

Built-in packs (`themes/<code>/`):

| Code | Look |
|---|---|
| `default` | original calm blue with a navy sidebar |
| `ocean` | corporate teal-blue |
| `emerald` | green — finance, agriculture |
| `sunset` | warm orange with a plum sidebar |
| `graphite` | neutral monochrome, violet accent, dark-first |
| `contrast` | high contrast (accessibility) with a thick focus ring |
| `gov-uz` | government portal: azure and green |

## Make your own theme in 3 steps

1. **Get a template.** Either:
   - click *Download the starter template* on the Appearance page, or
   - run `php yii theme:make my-brand --from=ocean`, which creates `themes/my-brand/`.
2. **Edit the files.**
   - `theme.json`: set `code`, `name` and the preview `colors`.
   - `theme.css`: set the token values in three blocks — light (`:root`), dark (`prefers-color-scheme`), and forced dark (`:root[data-theme="dark"]`).
3. **Install it.** Use one of these:
   - upload the `.zip` on the Appearance page, then preview and activate;
   - run `php yii theme:install my-brand.zip --activate`;
   - for a folder in `themes/`, run `php yii theme:activate my-brand`.

Other commands:

- `php yii theme:list` shows all themes and which one is active.
- `php yii theme:activate <code> --tenant=<uuid>` sets a tenant theme.

### theme.json

```json
{
  "code": "my-brand",
  "name": "My Brand",
  "version": "1.0.0",
  "author": "Team",
  "description": "Short text",
  "dark": true,
  "colors": {
    "primary": "#0b6bcb",
    "sidebar": "#06263f",
    "background": "#f5f7fb",
    "surface": "#ffffff",
    "dark_primary": "#4aa3ff",
    "dark_background": "#07131f"
  }
}
```

`colors` are used only for the preview card in the gallery.

### Token reference

| Group | Tokens |
|---|---|
| Surfaces | `--bg`, `--bg-elev`, `--bg-sunken`, `--bg-hover`, `--muted-soft` |
| Text | `--text`, `--text-2`, `--text-3` |
| Lines | `--border`, `--border-strong` |
| Brand | `--primary`, `--primary-hover`, `--primary-soft`, `--on-primary`, `--focus` |
| Status | `--ok`/`--ok-soft`, `--warn`/`--warn-soft`, `--bad`/`--bad-soft`, `--info`/`--info-soft` |
| Sidebar | `--sidebar-bg`, `--sidebar-text`, `--sidebar-text-strong`, `--sidebar-hover`, `--sidebar-active` |
| Shape | `--radius`, `--radius-sm`, `--shadow`, `--shadow-lg`, `--font`, `--mono` |

A few component rules after the tokens are allowed; keep them small.

## Security rules for uploaded packs

`ThemeValidator` rejects any pack that breaks one of these rules:

- **Archive:** a `.zip` of at most 512 KB that contains only `theme.json` and `theme.css`, optionally inside one folder. Nested paths, `..` and other files are refused.
- **CSS:** at most 200 KB of UTF-8, with balanced braces.
- **Forbidden in CSS:**
  - `@import` and `@namespace`
  - `url()` pointing to another host or a scheme (only `data:image/...` and relative paths are allowed)
  - `expression()`, `javascript:`, `vbscript:`, `behavior`, `-moz-binding`
  - any `<`
- **CSS escapes** are decoded before checking, so `\6A avascript:` is caught.

Uploaded packs are stored in the database (`appearance_themes`), so every instance serves the same CSS. They are
served by `/appearance/<code>.css?v=<hash>` (immutable cache, CSP `style-src 'self'`). Built-in codes cannot be
overwritten, and the active theme cannot be deleted.

## Files

- `themes/*/` — built-in packs.
- `src/Platform/Appearance/`:
  - `ThemeRegistry`, `ThemeValidator`, `ThemeService`
  - `AppearanceViewInjection` — gives layouts the `$theme` parameter.
- `src/Admin/Settings/AppearanceController.php`
- `src/Web/Account/AppearanceController.php`
- `src/Web/System/ThemeCssAction.php`
- `src/Console/Command/Theme*Command.php`
- Migration `M261105000003Appearance` — tables `appearance_themes` and `appearance_choice`.
- Tests:
  - `tests/Integration/ThemeServiceTest.php`
  - `tests/E2E/Admin/AppearanceTest.php`
