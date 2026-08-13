# PRC Content Transformer Suite

Open-source WordPress plugins for AI-powered content transformation, Apple News publishing, and email delivery — extracted from the [Pew Research Center publishing platform](https://github.com/pewresearch/prc-platform).

[![Try in Playground](https://img.shields.io/badge/Try%20in-Playground-3858e9?logo=wordpress)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/whyisjake/prc-content-transformer-suite/refs/heads/main/blueprint.json)

## Try it in Playground

Click the badge above to open a live WordPress demo in your browser — no local setup required.

The demo activates **prc-scripts**, **prc-icon-library**, **prc-markdown-for-agents**, and **markdown-comment-block**, then seeds a sample article and lands directly on its `/markdown` endpoint so you can see the output immediately.

**Demo flow:**

1. Playground loads and lands on `/demo-article/markdown` — a `text/markdown` response with YAML front matter
2. Navigate to `/demo-article.md` — same content via the `.md` URL convention
3. Navigate to `/llms.txt` — the site-level AI index
4. Open `/wp-admin/` → New Post → insert a **Markdown Comment** block from the block inserter

> **Note:** prc-apple-news, prc-email-builder, and prc-content-transformer are not included in the demo because they require API credentials (Apple News, Mailchimp) and PHP vendor dependencies. See [Local development](#local-development) to run the full suite locally.

## Plugins

| Plugin | Description |
|---|---|
| `prc-scripts` | Shared JS/CSS runtime (wp_enqueue_script handles, @prc/components, @prc/icons) |
| `prc-icon-library` | Font Awesome Free SVG sprite library (solid, regular, brands) |
| `prc-post-publish-pipeline` | Post-publish action pipeline (triggers downstream transformations) |
| `prc-markdown-for-agents` | Serve articles as Markdown via `.md` / `/markdown` URLs for AI agents and crawlers |
| `prc-pdf-extraction` | Extract text from PDF attachments via OCR (Claude, Gemini, WP AI) and expose via the markdown endpoint |
| `prc-content-transformer` | AI-powered middleware that converts WordPress content to Apple News Format, email HTML, or plain text |
| `prc-audio-narration` | Rewrites articles for the ear and synthesizes them to audio via a pluggable text-to-speech layer |
| `prc-apple-news` | Publishes content to Apple News via the Apple News API |
| `prc-email-builder` | Newsletter authoring and Mailchimp delivery (CPT, patterns, send UI) |
| `markdown-comment-block` | Gutenberg block that renders Markdown in the editor |

## Requirements

**WordPress:** 6.8+
**PHP:** 8.2+
**Node.js:** 22.16+ (see `.nvmrc`)

### External prerequisites

These plugins provide deep integration but are **not bundled** here:

- [ai-provider-for-anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic/) — Anthropic/Claude provider for the WordPress AI client; auto-installed in the local wp-env environment
- [Action Scheduler](https://actionscheduler.org/) — bundled via `woocommerce/action-scheduler` Composer package in `prc-content-transformer`
- Apple News API credentials — configure in **Settings → Apple News** after activation
- Mailchimp API key — configure in **Settings → Email Builder** after activation
- ElevenLabs API key — required by `prc-audio-narration` for speech synthesis; configure in **Settings → Audio Narration** after activation

## Local development

The local environment is managed by [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/). It installs `ai-provider-for-anthropic` automatically from the WordPress plugin directory.

```bash
# 1. Clone and install JS dependencies
git clone https://github.com/pewresearch/prc-content-transformer-suite.git
cd prc-content-transformer-suite
npm install

# 2. Add your Anthropic API key to .wp-env.json
#    Edit the "config" block and fill in the value:
#    "ANTHROPIC_API_KEY": "sk-ant-..."

# 3. Start the environment
npx @wordpress/env start
```

The environment will be available at `http://localhost:8888` (admin: `http://localhost:8888/wp-admin`, user/pass: `admin` / `password`).

If those ports are already taken, create a `.wp-env.override.json` (gitignored) with different `port` / `testsPort` values rather than editing `.wp-env.json`.

### Running tests

PHP tests run inside the wp-env test container:

```bash
# Install the plugin's PHP dependencies once
cd plugins/prc-audio-narration && composer install && cd ../..

# Start the environment, then run the suite
npm run env -- start
npm run test:php
```

CI runs this same command on every pull request. Only `prc-audio-narration` is wired into that job today — see the comments in `.github/workflows/ci.yml` for what blocks the other plugins' suites from running.

### API key configuration

`prc-content-transformer` and `prc-pdf-extraction` both need an Anthropic API key. The plugins resolve it in this order:

1. `ANTHROPIC_API_KEY` PHP constant (set via `.wp-env.json` `config`)
2. `PRC_PLATFORM_ANTHROPIC_API_KEY` PHP constant
3. `connectors_ai_anthropic_api_key` / `ais_anthropic_api_key` WordPress options (set automatically when you configure the **ai-provider-for-anthropic** plugin via Settings → AI)

For local wp-env development, the simplest approach is to fill in the `ANTHROPIC_API_KEY` value in `.wp-env.json` before running `npx @wordpress/env start`.

`prc-audio-narration` needs an ElevenLabs API key, resolved in the same style:

1. `ELEVENLABS_API_KEY` PHP constant (set via `.wp-env.json` `config`)
2. `PRC_PLATFORM_ELEVENLABS_API_KEY` PHP constant
3. The key saved in **Settings → Audio Narration**

Constants take precedence, so a server-level key cannot be overridden from the admin screen. Narration is only ever generated when an editor explicitly asks for it — there is no hook on publish — because synthesis is billed per character and report-length content is long.

### WP-CLI

#### Content transformer

```bash
# Transform a post to Apple News Format, email HTML, or plain text
npx @wordpress/env run cli wp prc content-transformer transform <post_id> --provider=apple-news
npx @wordpress/env run cli wp prc content-transformer transform <post_id> --provider=email
npx @wordpress/env run cli wp prc content-transformer transform <post_id> --provider=plain-text

# Force re-transform (bypass cache)
npx @wordpress/env run cli wp prc content-transformer transform <post_id> --provider=apple-news --force

# Save output to a file
npx @wordpress/env run cli wp prc content-transformer transform <post_id> --provider=apple-news --output-file=article.json

# List registered providers
npx @wordpress/env run cli wp prc content-transformer providers

# Check cache status
npx @wordpress/env run cli wp prc content-transformer status <post_id> --provider=plain-text

# Clear cache
npx @wordpress/env run cli wp prc content-transformer clear-cache <post_id>
npx @wordpress/env run cli wp prc content-transformer clear-cache <post_id> --provider=apple-news
```

#### PDF extraction

```bash
# List available OCR providers
npx @wordpress/env run cli wp prc pdf list-providers

# Extract text from a PDF attachment (by attachment ID)
npx @wordpress/env run cli wp prc pdf process <attachment_id>

# Estimate extraction cost
npx @wordpress/env run cli wp prc pdf estimate-cost <attachment_id>

# Validate a prior extraction result
npx @wordpress/env run cli wp prc pdf validate <attachment_id>

# List past extractions
npx @wordpress/env run cli wp prc pdf list-extractions
```

## Installation (production)

```bash
# 1. Clone
git clone https://github.com/pewresearch/prc-content-transformer-suite.git
cd prc-content-transformer-suite

# 2. Install JS dependencies and build
npm install
npm run build

# 3. Install PHP dependencies (per-plugin)
cd plugins/prc-content-transformer && composer install
cd ../prc-apple-news && composer install
cd ../prc-email-builder && composer install
cd ../prc-markdown-for-agents && composer install

# 4. Activate plugins in WordPress (order matters)
#    prc-scripts → prc-icon-library → prc-post-publish-pipeline
#    → prc-markdown-for-agents → prc-pdf-extraction → prc-content-transformer
#    → prc-apple-news → prc-email-builder → markdown-comment-block
```

> **Note:** `prc-email-builder` depends on `@prc/components`, a private package from the Pew Research Center platform. Its JS assets are committed pre-built to this repository. If you need to rebuild it, you will need access to the internal npm registry.

## License

GPL-2.0-or-later. See individual plugin directories for `LICENSE` files.

Font Awesome Free icons (solid, regular, brands) are licensed under [SIL OFL 1.1](https://scripts.sil.org/OFL) and [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md) for responsible disclosure instructions.
