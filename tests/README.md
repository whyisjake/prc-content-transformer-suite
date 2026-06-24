# Tests

Each plugin carries its own PHPUnit test suite under `plugins/<name>/tests/`. This directory is reserved for cross-plugin integration tests that require the full WordPress environment.

## Running per-plugin tests

```bash
# Start the wp-env environment first
wp-env start

# Run PHPUnit for a specific plugin
wp-env run tests-wordpress vendor/bin/phpunit \
  --configuration /var/www/html/wp-content/plugins/prc-apple-news/phpunit.xml.dist
```

## Cross-plugin integration tests

Cross-plugin tests (e.g. confirming that `prc-content-transformer` → `prc-apple-news` round-trip works) should live in this directory as `tests/php/integration/`.

These require all 8 plugins to be active in the wp-env environment (`.wp-env.json` declares them all).

## Prerequisites

- Docker running (for `wp-env`)
- `@wordpress/env` installed: `npm install -g @wordpress/env`
- `composer install` run inside each plugin that has PHP dependencies
