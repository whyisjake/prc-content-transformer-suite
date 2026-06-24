# PRC Icon Library (Font Awesome)

Open-source reference plugin that wires Font Awesome **sprite-based** icons into the PRC Platform. It defines `PRC_PLATFORM_ICONS_URL` / `PRC_PLATFORM_ICONS_PATH` and ships a build script to populate `build/icons/sprites/`.

**Public repo note:** The sibling repository at [pewresearch/prc-icon-library](https://github.com/pewresearch/prc-icon-library) includes **only `brands.svg`** (Font Awesome Free Brands). All other sprite files are stripped at release time because they require a [Font Awesome Pro](https://fontawesome.com/) license. Clone this monorepo plugin or run `bash bin/build.sh` locally with your own FA Pro kit to obtain the full sprite set.

## Status / future direction

PRC intends to **transition away from vendored SVG sprites** toward **WordPress core's SVG Icon API** as it stabilizes:

- `WP_Icons_Registry` — core icon registry (shipped in WP 7.0)
- `register_icon_collection()` / `register_icon()` — custom icon registration ([gutenberg#77260](https://github.com/WordPress/gutenberg/pull/77260))
- Reusable `IconPickerModal` in the block editor ([gutenberg#76787](https://github.com/WordPress/gutenberg/pull/76787))

Track overall progress on [WordPress/gutenberg#75715](https://github.com/WordPress/gutenberg/issues/75715) (*SVG Icon API: Iteration for WordPress 7.1*). Once collection registration and inline RichText icon insertion land in core, this plugin will register PRC glyphs as a core icon collection instead of maintaining a parallel sprite loader in `prc-scripts`.

Until that migration, the sprite-reference pattern documented here remains the supported approach for `prc-block-bits/icon-span`, shareable-text brand icons, and `\PRC\Platform\Icons\render()`.

## What it does

- Defines `PRC_PLATFORM_ICONS_URL` and `PRC_PLATFORM_ICONS_PATH` at plugin load time so `prc-scripts` icon helpers can resolve sprite URLs
- Provides `bin/build.sh` to copy sprites from a licensed `@awesome.me` kit into `build/icons/sprites/` (not run in CI for the public repo)
- On the PRC Platform (private monorepo), all Font Awesome Pro style sprites are vendored under `build/icons/sprites/` for build-time efficiency

## Key files

| File | Purpose |
| --- | --- |
| `prc-icon-library.php` | Plugin entry point; defines `PRC_PLATFORM_ICONS_URL` and `PRC_PLATFORM_ICONS_PATH` constants |
| `bin/build.sh` | Build script; copies sprites from `node_modules/@awesome.me/kit-*/icons/sprites/` into `build/icons/sprites/` |
| `build/icons/sprites/*.svg` | Compiled SVG sprite files (one per library); **only `brands.svg` in the public repo** |
| `package.json` | npm workspace config; lists Font Awesome Pro packages and the custom kit as optional dependencies |

## Constants defined

| Constant | Value |
| --- | --- |
| `PRC_PLATFORM_ICONS_URL` | `{plugin_dir_url}/build/icons/sprites/` (trailing slash) |
| `PRC_PLATFORM_ICONS_PATH` | `{plugin_dir_path}/build/icons/sprites/` (trailing slash) |

Both constants are consumed by `\PRC\Platform\Icons\get_icon_as_url()` and related helpers in `prc-scripts`. If the plugin is inactive or sprites are missing, those functions return HTML comments rather than throwing.

## Sprite libraries

| Sprite file | Library name | In public repo |
| --- | --- | --- |
| `brands.svg` | `brands` | Yes (FA Free Brands, SIL OFL 1.1 / CC BY 4.0) |
| `solid.svg` | `solid` | No — build locally with FA Pro |
| `regular.svg` | `regular` | No |
| `light.svg` | `light` | No |
| `thin.svg` | `thin` | No |
| `duotone.svg` | `duotone` | No |
| `sharp-solid.svg` | `sharp-solid` | No |
| `sharp-regular.svg` | `sharp-regular` | No |
| `sharp-light.svg` | `sharp-light` | No |
| `sharp-thin.svg` | `sharp-thin` | No |
| `sharp-duotone-solid.svg` | `sharp-duotone-solid` | No |
| `custom-icons.svg` | `custom-icons` | No (internal PRC kit sprite; not redistributed) |

## Filters / hooks

This plugin registers no hooks or filters beyond a `robots_txt` disallow for `/wp-content/plugins/prc-icon-library/` on public sites (both the catch-all `User-agent: *` group and the dedicated `User-agent: Googlebot` group, since Googlebot does not inherit rules from `*`). All rendering logic lives in `prc-scripts`.

## Usage

Icon rendering is handled by functions in the `PRC\Platform\Icons` namespace (defined in `prc-scripts/includes/utils.php`).

**Render an icon as inline HTML (cached, `<i>` wrapper):**

```php
echo \PRC\Platform\Icons\render( 'solid', 'arrow-right', 1.25 );
```

**Get an icon as an SVG fragment (cached, useful for server-side templating):**

```php
$svg = \PRC\Platform\Icons\get_icon_as_svg( 'regular', 'magnifying-glass', '#333' );
```

**Get an icon as a data URI (useful for CSS `background-image`):**

```php
$uri = \PRC\Platform\Icons\get_icon_as_data_uri( 'light', 'circle-check' );
```

**Get a raw sprite fragment URL (used by `prc-block-bits/icon-span`):**

```php
$url = \PRC\Platform\Icons\get_icon_as_url( 'solid', 'arrow-right' );
// https://.../build/icons/sprites/solid.svg#arrow-right
```

Icons are object-cached for 7 days (`prc_icons__rendered` / `prc_icons__svg` groups). Cache keys include `PRC_PLATFORM_VERSION`, so a version bump invalidates cached markup automatically.

## Build

Sprite files are **not** produced by `npm run build` in CI for the public repository. Populate them locally:

```bash
# From monorepo root (requires PRC_PLATFORM_FONTAWESOME_TOKEN)
npm install
bash plugins/prc-icon-library/bin/build.sh

# Or from the plugin directory
cd plugins/prc-icon-library && bash ./bin/build.sh
```

The script expects `node_modules/@awesome.me/kit-329ff3ff3e` (or another `kit-*` folder) after `npm install`. If install fails with 404 errors for `@fortawesome/*` packages, set `PRC_PLATFORM_FONTAWESOME_TOKEN` and regenerate `.npmrc` via `bash bin/setup/generate-npmrc.sh` from the repo root.

After building, regenerate the editor icon index used by `@prc/icons` / `IconPicker`:

```bash
node plugins/prc-scripts/includes/scripts/src/@prc/icons/bin/build-index.js
npx turbo build --filter=@prc/icons
```

## Dependencies

| Dependency | Type | Notes |
| --- | --- | --- |
| `prc-scripts` | WordPress plugin | Required; provides `\PRC\Platform\Icons\*` render helpers |
| `@awesome.me/kit-329ff3ff3e` | npm (private) | Custom Font Awesome kit; requires `PRC_PLATFORM_FONTAWESOME_TOKEN` |
| `@fortawesome/fontawesome-pro` | npm (private) | Font Awesome Pro; requires `PRC_PLATFORM_FONTAWESOME_TOKEN` |
| `@fortawesome/free-brands-svg-icons` | npm (public) | Source for `brands.svg` |

## Licensing

| Asset | License |
| --- | --- |
| Plugin PHP, `bin/build.sh`, `package.json` | GPL-2.0-or-later ([LICENSE](LICENSE)) |
| `brands.svg` | Font Awesome Free Brands — [SIL OFL 1.1](https://scripts.sil.org/OFL) / [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) |
| All other sprites (`solid`, `regular`, Pro styles, `custom-icons`) | **Not redistributed** in the public repo. Build locally under your [Font Awesome Pro](https://fontawesome.com/license) license. |

Font Awesome is a trademark of Fonticons, Inc.

## Notes

- Sprite-based icons use `<use href="…">` references. Sprites must be served from the **same origin** as the page or browsers silently fail to render.
- The `@awesome.me` kit used internally includes PRC custom glyphs in `custom-icons.svg`. Add icons through the Font Awesome kit dashboard, then rebuild.
- The plugin defines constants unconditionally at load time; loading it twice will trigger a PHP notice. Keep it in the standard plugins directory only.
