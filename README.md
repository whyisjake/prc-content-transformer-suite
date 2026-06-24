# PRC Content Transformer Suite

Open-source WordPress plugins for AI-powered content transformation, Apple News publishing, and email delivery — extracted from the [Pew Research Center publishing platform](https://github.com/pewresearch/prc-platform).

## Plugins

| Plugin | Description |
|---|---|
| `prc-scripts` | Shared JS/CSS runtime (wp_enqueue_script handles, @prc/components, @prc/icons) |
| `prc-icon-library` | Font Awesome Free SVG sprite library (solid, regular, brands) |
| `prc-post-publish-pipeline` | Post-publish action pipeline (triggers downstream transformations) |
| `prc-markdown-for-agents` | Serve articles as Markdown via `.md` / `/markdown` URLs for AI agents and crawlers |
| `prc-content-transformer` | AI-powered middleware that converts WordPress content to Apple News Format, email HTML, or plain text |
| `prc-apple-news` | Publishes content to Apple News via the Apple News API |
| `prc-email-builder` | Newsletter authoring and Mailchimp delivery (CPT, patterns, send UI) |
| `markdown-comment-block` | Gutenberg block that renders Markdown in the editor |

## Requirements

**WordPress:** 6.8+  
**PHP:** 8.2+  
**Node.js:** 22.16+ (see `.nvmrc`)

### External prerequisites

These plugins provide deep integration but are **not bundled** here:

- [WordPress AI plugin](https://wordpress.org/plugins/ai/) — enables AI-powered transformation in `prc-content-transformer`
- [Action Scheduler](https://actionscheduler.org/) — bundled via `woocommerce/action-scheduler` Composer package in `prc-content-transformer`
- Apple News API credentials — configure in **Settings → Apple News** after activation
- Mailchimp API key — configure in **Settings → Email Builder** after activation

## Installation

```bash
# 1. Clone
git clone https://github.com/pewresearch/prc-content-transformer-suite.git
cd prc-content-transformer-suite

# 2. Install JS dependencies and build
npm install
npm run build

# 3. Install PHP dependencies (per-plugin, after npm build)
cd plugins/prc-content-transformer && composer install
cd ../prc-apple-news && composer install
cd ../prc-email-builder && composer install
cd ../prc-markdown-for-agents && composer install

# 4. Activate plugins in WordPress (order matters)
#    prc-scripts → prc-icon-library → prc-post-publish-pipeline
#    → prc-markdown-for-agents → prc-content-transformer
#    → prc-apple-news → prc-email-builder → markdown-comment-block
```

## Local development

```bash
# Start a local WordPress environment (requires @wordpress/env)
npm install -g @wordpress/env
wp-env start
```

## License

GPL-2.0-or-later. See individual plugin directories for `LICENSE` files.

Font Awesome Free icons (solid, regular, brands) are licensed under [SIL OFL 1.1](https://scripts.sil.org/OFL) and [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md) for responsible disclosure instructions.
