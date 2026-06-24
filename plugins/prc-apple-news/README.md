# PRC Apple News

Publishes Pew Research Center posts to Apple News via the Apple News API. Converts Gutenberg block content into Apple News Format (ANF) JSON using a deterministic block-walking pipeline, validates output, and pushes articles asynchronously on production publish.

## What it does

- Converts `post` and `short-read` block content to ANF JSON via `ANF_Block_Converter` (deterministic walk order; no randomness between runs for the same post)
- Pushes to Apple News on production publish via Action Scheduler (`prc_apple_news_push` in group `prc-apple-news`)
- Editor sidebar panel with live ANF preview, push/delete controls, and error dismissal
- Admin settings page (Settings → Apple News) for API credentials and channel UUID
- REST API for push, delete, status, preview, and error management
- WP-CLI commands under `wp prc apple-news`

## ANF conversion pipeline

`ANF_Block_Converter` walks parsed blocks in document order:

1. **`block.json` `prcAppleNewsAnf`** — declarative resolver config injected into `supports` at registration (`ANF_Block_Resolver`)
2. **`ANF_Block_Registry`** — imperative callbacks registered on `prc_apple_news_register_block_callbacks` (fires on `init` priority 5)
3. **Container recursion** — transparent blocks (`core/group`, `core/column`, `core/columns`) recurse into inner blocks when descendants are handled
4. **`prc-content-transformer`** — contiguous unhandled block runs fall through to the shared content transformer (markdown/HTML → ANF components)

After conversion, `ANF_Post_Processor` normalizes typography and layout; `ANF_Validator` checks schema and referential integrity before push.

### Registering a block handler

**Declarative (preferred)** — add `prcAppleNewsAnf` to the block's `block.json`:

```json
{
  "name": "my-plugin/my-block",
  "prcAppleNewsAnf": {
    "callback": "my_plugin_anf_component"
  }
}
```

Supported `mode` values: `strip` (omit block), `children-only` (recurse into inner blocks), or omit for `html-fallback`. When `callback` is a callable PHP function name, it produces ANF components directly.

**Imperative** — register on the action hook:

```php
add_action( 'prc_apple_news_register_block_callbacks', function () {
    \PRC\Platform\Apple_News\ANF\ANF_Block_Registry::register(
        'my-plugin/my-block',
        function ( array $block, \WP_Post $post ): array {
            return array( /* ANF component arrays */ );
        }
    );
} );
```

Callbacks receive the parsed block array and `WP_Post`, and return zero or more ANF component arrays.

## Key files

| File | Purpose |
| --- | --- |
| `prc-apple-news.php` | Plugin entry; activation seeds default settings |
| `includes/class-bootstrap.php` | Loads dependencies and wires hooks |
| `includes/class-post-sync.php` | Production publish → AS push job; core push logic |
| `includes/class-rest-controller.php` | REST routes under `prc-apple-news/v1` |
| `includes/class-settings.php` | Admin settings page + credentials REST |
| `includes/class-editor-assets.php` | Enqueues sidebar React app on supported post types |
| `includes/anf/class-anf-block-converter.php` | Deterministic block → ANF JSON conversion |
| `includes/anf/class-anf-block-resolver.php` | Resolves `prcAppleNewsAnf` metadata from `block.json` |
| `includes/anf/class-anf-block-registry.php` | Static registry for imperative block callbacks |
| `includes/apple-news-api/class-api.php` | Apple News API client |
| `includes/cli/class-cli-command.php` | `wp prc apple-news` commands |

## REST API

Namespace: `prc-apple-news/v1`. Push/delete/preview routes require `edit_post` on the target post.

| Method | Route | Description |
| --- | --- | --- |
| `POST` | `/push` | Trigger push for a post (`post_id`, optional `force`) |
| `POST` | `/delete` | Remove article from Apple News |
| `GET` | `/status` | Current sync status and metadata for a post |
| `GET` | `/preview` | Processed ANF document for in-editor preview |
| `DELETE` | `/error` | Dismiss stored push error |
| `GET`/`POST` | `/settings` | Read/write API credentials (admin) |
| `GET` | `/test` | Test API connectivity |

## WP-CLI

```bash
wp prc apple-news push <post_id> [--force] [--preview] [--hidden]
wp prc apple-news delete <post_id>
wp prc apple-news status <post_id>
wp prc apple-news preview <post_id> [--output=<file>]
wp prc apple-news create-fixture <post_id> [--output=<dir>]
wp prc apple-news bulk-push [--post-type=post] [--limit=<n>] [--dry-run]
```

## Hooks

| Hook | Direction | Description |
| --- | --- | --- |
| `prc_apple_news_register_block_callbacks` | Action (`init`, priority 5) | Register imperative ANF block callbacks |
| `prc_apple_news_push` | Action Scheduler | Async push job; args: `post_id` |
| `prc_apple_news_block_{block_name}` | Filter | Filter ANF components after registry callback (e.g. `prc_apple_news_block_core/paragraph`) |
| `prc_apple_news_fallback_components` | Filter | Override transformer fallback for unhandled block runs |
| `prc_apple_news_production_media_url` | Filter | Base URL for production media resolution in ANF post-processing |

## Dependencies

| Dependency | Notes |
| --- | --- |
| `prc-scripts` | Required plugin |
| `prc-content-transformer` | Fallback conversion for unhandled block runs |
| `prc-markdown-for-agents` | Markdown conversion utilities used by the transformer path |
| Action Scheduler | Async push jobs on production publish |

## Build

```bash
npx turbo build --filter=@prc/apple-news
# or watch:
npm run start -w @prc/apple-news
```

Builds the settings page and editor sidebar apps to `build/settings/` and `build/sidebar/`.

## Notes

- Auto-push on publish runs only when `wp_get_environment_type() === 'production'`.
- Supported post types: `post`, `short-read`.
- PHPUnit tests live in `tests/php/`; kitchen-sink fixtures under `tests/php/fixtures/`.
