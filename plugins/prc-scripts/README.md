# PRC Scripts

Shared first-party (`@prc/*`) and third-party JavaScript, stylesheets, and script modules for all PRC Platform plugins.

This plugin owns the `Scripts` and `Script_Modules` registration classes that were previously bundled inside `prc-platform-core`. Every leaf `prc-*` plugin that consumes shared script handles (e.g. `prc-components`, `prc-controls`, `prc-charting-utilities`) declares `Requires Plugins: prc-scripts` in its main plugin file header.

PHP namespaces (`PRC\Platform\Scripts`, `PRC\Platform\Script_Modules`) are unchanged from the previous home so REST API endpoint classes that hardcode them keep working.

## Layout

- `includes/scripts/` — first-party `@prc/*` and third-party JavaScript / CSS source + build artifacts.
- `includes/script-modules/` — `@prc/d3` and `@prc/topojson` script modules.
- `includes/class-bootstrap.php` — wires the moved classes into the new plugin's `Loader`.

## Load order

`prc-scripts` is registered in `client-mu-plugins/plugin-loader.php` after `prc-icon-library` / `prc-post-publish-pipeline` and immediately before `prc-block-library`, ensuring its handles are available before any plugin that lists it in `Requires Plugins:`.
