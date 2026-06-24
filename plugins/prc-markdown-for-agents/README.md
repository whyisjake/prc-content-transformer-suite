# PRC Markdown for Agents

Serves WordPress post content as Markdown for AI agents and crawlers via `.md` and `/markdown` URL endpoints (and `rel="alternate"` discovery in HTML).

## Overview

This plugin exposes every supported post type as Markdown through explicit URL suffixes (`/my-article.md` or `/my-article/markdown`) and `<link rel="alternate" type="text/markdown">` discovery tags in the HTML head. The output includes YAML frontmatter with post metadata, a block-by-block converted body, and response headers that signal token count and content-use permissions. It exists to give AI agents a clean, structured alternative to scraping rendered HTML.

> **Accept header negotiation is temporarily disabled** (default `PRC_MARKDOWN_FOR_AGENTS_ENABLE_ACCEPT_NEGOTIATION = false`) due to VIP edge-cache behavior on canonical URLs — see [Linear PRC-466](https://linear.app/pewresearch/issue/PRC-466). Agents should follow `rel="alternate"` links to `.md` or `/markdown` endpoints.

The plugin integrates with four other platform plugins (staff bylines, datasets, PDF extraction, report packages) to enrich frontmatter and append contextual navigation links. Other plugins can register their own block-level Markdown callbacks through an action-based extension point.

### Dependencies

- **Upstream**: `prc-scripts` (required), `prc-staff-bylines` (optional — richer author data), `prc-datasets` (optional — dataset frontmatter), `prc-pdf-extraction` (optional — extraction URL in frontmatter), `prc-report-package` (optional — next-chapter navigation links)
- **Downstream**: Any plugin or external agent that reads `.md` or `/markdown` URLs

## Architecture

Bootstrap loads all classes and wires these entry points:

1. **URL rewriting** — `parse_request` intercepts paths ending in `.md` or `/markdown`, resolves them to posts via `url_to_postid()`, and serves Markdown directly. Optional `?markdown=true` on the canonical URL also serves Markdown.
2. **Discovery** — `wp_head` injects `<link rel="alternate" type="text/markdown">` tags pointing to both `.md` and `/markdown` endpoints so agents can find them without guessing.
3. **Robots.txt** — `robots_txt` filter injects a `Content-Signal:` directive (Cloudflare proposal) under `User-agent: *` so crawlers can discover the site's AI/search consent stance without having to fetch a markdown response first.
4. **Content negotiation (disabled by default)** — when `PRC_MARKDOWN_FOR_AGENTS_ENABLE_ACCEPT_NEGOTIATION` is true, `template_redirect` inspects the `Accept` header and hijacks the response when `text/markdown` is preferred. Disabled on VIP until edge cache partitioning is fixed ([PRC-466](https://linear.app/pewresearch/issue/PRC-466)).

When a Markdown response is triggered, `Markdown_Response::serve()` runs: it calls `Markdown_Converter::post_to_markdown()`, prepends YAML frontmatter from `Frontmatter::build()`, sets response headers (`Content-Type: text/markdown`, `Vary: Accept`, `X-Markdown-Tokens`, `Content-Signal`, `X-Robots-Tag: noindex`), and exits.

The converter walks the parsed block tree. Blocks with a registered callback in `Block_Markdown_Registry` produce their own Markdown. Container blocks (e.g. `core/group`) are recursed. Everything else falls through to `render_block()` → `HTML_To_Markdown_Converter`.

Post type support is opt-in via `add_post_type_support( $type, 'prc-markdown-for-agents' )`. `post` and `page` are registered by default.

### Key Files

| Path                                            | Purpose                                                                                                      |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `prc-markdown-for-agents.php`                   | Plugin entry point; defines constants, activation hooks                                                      |
| `includes/class-bootstrap.php`                  | Loads dependencies, wires all modules                                                                        |
| `includes/class-content-negotiation.php`        | `Accept: text/markdown` header detection (disabled by default; see PRC-466)                                  |
| `includes/class-rewrite-rules.php`              | `.md` and `/markdown` URL interception via `parse_request`                                                   |
| `includes/class-discovery.php`                  | Injects `<link rel="alternate">` tags in `wp_head`                                                           |
| `includes/class-robots-txt.php`                 | Injects `Content-Signal:` directive into `robots.txt`                                                        |
| `includes/class-markdown-converter.php`         | Block tree walker; dispatches to callbacks or HTML converter                                                 |
| `includes/class-html-to-markdown-converter.php` | HTML → Markdown via WP HTML API (ported from `wordpress/ai` PR #194)                                         |
| `includes/class-frontmatter.php`                | YAML frontmatter builder (title, description, date, authors, categories, tags)                               |
| `includes/class-markdown-response.php`          | Sets headers and outputs the final Markdown response                                                         |
| `includes/class-block-markdown-registry.php`    | Static registry mapping block names to Markdown callbacks                                                    |
| `includes/class-staff-bylines-integration.php`  | Populates `authors` frontmatter from `prc-staff-bylines`                                                     |
| `includes/class-datasets-integration.php`       | Adds `datasets` to frontmatter from the `datasets` taxonomy                                                  |
| `includes/class-pdf-extraction-integration.php` | Adds PDF extraction URL to frontmatter when available                                                        |
| `includes/class-report-package-integration.php` | Prepends TOC for report roots with materials; appends next-chapter link to Markdown body for report packages |
| `includes/class-loader.php`                     | Hook registration helper (action/filter queue)                                                               |

## Hooks & Filters

| Hook                                               | Type   | Description                                                                                                                                                                                                                                                                                                                            |
| -------------------------------------------------- | ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `prc_markdown_for_agents_register_block_callbacks` | Action | Fires at `init` priority 5. Use `Block_Markdown_Registry::register( $block_name, $callable )` inside this action to map a block type to a Markdown callback. The callable receives `( array $block, WP_Post $post )` and must return a string.                                                                                         |
| `prc_markdown_for_agents_pre_markdown`             | Filter | `( string\|null $pre, WP_Post $post )` — Return a non-null string to bypass the block conversion pipeline entirely. Useful for post types that store pre-built Markdown (e.g. OCR-extracted content).                                                                                                                                  |
| `prc_markdown_for_agents_block_{$block_name}`      | Filter | `( string $block_md, array $block, WP_Post $post )` — Filters the Markdown produced by a block's registered callback. The dynamic segment is the full block name (e.g. `prc-chart-builder/controller`).                                                                                                                                |
| `prc_markdown_for_agents_authors`                  | Filter | `( array $authors, WP_Post $post )` — Populate or override the `authors` frontmatter field. Each entry is an array with at least a `name` key; `job_title` and `link` are optional.                                                                                                                                                    |
| `prc_markdown_for_agents_frontmatter`              | Filter | `( array $data, WP_Post $post )` — Modify the full frontmatter data array before it is serialized to YAML. Keys with empty values are stripped automatically.                                                                                                                                                                          |
| `prc_markdown_for_agents_after_markdown`           | Filter | `( string $markdown_body, WP_Post $post )` — Append or transform the Markdown body after conversion but before the response is sent. Used by the report package integration to add next-chapter links.                                                                                                                                 |
| `prc_markdown_for_agents_toc_for_post`             | Filter | `( string $toc_markdown, WP_Post $post )` — Return the table-of-contents Markdown for the given post, or the passed-through value if no TOC. Implementers (e.g. prc-block-library) return TOC when the post is part of a report package; the markdown plugin uses this to prepend TOC after the title for report roots with materials. |

### Registering a Block Callback

```php
add_action( 'prc_markdown_for_agents_register_block_callbacks', function() {
    \PRC\Platform\Markdown_For_Agents\Block_Markdown_Registry::register(
        'my-plugin/my-block',
        function( array $block, \WP_Post $post ): string {
            return '> ' . ( $block['attrs']['quote'] ?? '' );
        }
    );
} );
```

### Enabling Markdown for a Custom Post Type

```php
add_action( 'init', function() {
    add_post_type_support( 'my-cpt', 'prc-markdown-for-agents' );
} );
```

### Content-Signal

The `Content-Signal` value is driven by the `prc_markdown_for_agents_content_signal` option (stored via `get_option`). Default value:

```php
[
    'ai-train' => 'yes',
    'search'   => 'yes',
    'ai-input' => 'yes',
]
```

It is published in two places:

- **HTTP header** on every Markdown response (`Content-Signal: ai-train=yes, search=yes, ai-input=yes`).
- **`robots.txt` directive** under the `User-agent: *` group, per the [Cloudflare Content Signals proposal](https://developers.cloudflare.com/bots/concepts/content-signals/), so crawlers can read it without first fetching a `.md` URL.

Update this option in `wp-admin` → Options or via WP-CLI to change the signal sent with every Markdown response and in `robots.txt`.

### Re-enabling Accept header negotiation

Accept negotiation is off by default (`PRC_MARKDOWN_FOR_AGENTS_ENABLE_ACCEPT_NEGOTIATION`). To re-enable after [PRC-466](https://linear.app/pewresearch/issue/PRC-466) is resolved:

```php
define( 'PRC_MARKDOWN_FOR_AGENTS_ENABLE_ACCEPT_NEGOTIATION', true );
```

Add that to `wp-config.php` or a must-use plugin. Purge VIP edge cache after enabling.

## Local Development

```bash
npm run build -w @prc/markdown-for-agents
npm run start -w @prc/markdown-for-agents
```

This plugin is PHP-only; there is no compiled JavaScript. The build commands are present for monorepo consistency but produce no output.

## Troubleshooting

### `.md` URLs return 404

**Symptom**: Visiting `/politics/2025/01/my-article.md` returns a 404 instead of Markdown.
**Cause**: The post type is not opted into `prc-markdown-for-agents` support, or the post status is not `publish`.
**Fix**: Call `add_post_type_support( $type, 'prc-markdown-for-agents' )` at `init`, and confirm the target post is published.

### HTML conversion falls back to plain text

**Symptom**: Markdown output for some blocks is stripped of formatting.
**Cause**: `WP_HTML_Processor::create_fragment()` returned an error for unsupported HTML structures, and the `WP_HTML_Tag_Processor` fallback strips formatting it cannot parse.
**Fix**: Register a block-specific callback via `Block_Markdown_Registry::register()` to produce correct Markdown for that block type rather than relying on the generic HTML converter.

### Report package "next page" link appends debug text

**Symptom**: Markdown body ends with `No report package integration found` or `Post is not a chapter of a report package`.
**Cause**: The `Report_Package_Integration` class appends diagnostic strings when `prc-report-package` is inactive or the post is not a report chapter. This is a known issue in the integration class.
**Fix**: Ensure `prc-report-package` is active. If the post is intentionally not part of a report package, the debug text can be suppressed by filtering `prc_markdown_for_agents_after_markdown` to strip it.

### Authors field shows "Pew Research Center" instead of real authors

**Symptom**: Every post's frontmatter has `name: "Pew Research Center"` regardless of actual authorship.
**Cause**: `prc-staff-bylines` is inactive or has no bylines assigned to the post, and the WP user fallback also returned no display name.
**Fix**: Activate `prc-staff-bylines` and assign bylines, or hook `prc_markdown_for_agents_authors` to return the correct author data.

## Related Docs

- [WordPress/ai PR #194](https://github.com/WordPress/ai/pull/194) — upstream source for `HTML_To_Markdown_Converter`; once merged, the local copy in this plugin should be replaced with the upstream package
- [WP_HTML_Processor documentation](https://developer.wordpress.org/reference/classes/wp_html_processor/) — used for HTML parsing during conversion
