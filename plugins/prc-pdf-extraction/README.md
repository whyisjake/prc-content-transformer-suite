# PRC PDF Extraction

Extracts text from topline survey PDFs using AI OCR providers and surfaces the content as web pages, Markdown, and plain text for researchers and LLMs.

## Overview

This plugin reads `topline`-type entries from a parent post's `reportMaterials` meta, runs AI OCR on the attached PDFs, and persists each result as a `pdf_extraction` child post. Extracted content is available at `/{parent-slug}/extraction` (Markdown with YAML frontmatter) and `/{parent-slug}/text` (plain text). Extraction jobs are queued asynchronously via Action Scheduler and can also be triggered from WP-CLI or the block editor via REST API. The plugin integrates with `prc-markdown-for-agents` to serve standardized Markdown responses with proper headers for LLM discovery.

### Dependencies

-   **Upstream**: `prc-platform-core` (required), `prc-markdown-for-agents` (optional — Markdown endpoint falls back to a legacy template when absent), Action Scheduler (required for async processing)
-   **External APIs**: `PRC_PLATFORM_ANTHROPIC_API_KEY` for Claude (primary provider), `PRC_PLATFORM_GOOGLE_API_KEY` for Gemini (fallback provider)
-   **Downstream**: Nothing depends on this plugin directly; consumers access extractions through public URL endpoints or via the `pdf_extraction` post type.

## Architecture

Extractions follow a request → queue → process → persist flow:

1. An editor triggers extraction from the block editor (REST) or WP-CLI.
2. `Action_Scheduler_Handler::schedule()` enqueues an async job.
3. On the job callback, `Extraction_Service` resolves the PDF (local uploads dir or remote download), calls `OCR_Orchestrator::extract_text()`, and saves the result as a `pdf_extraction` CPT post.
4. `OCR_Orchestrator` tries registered providers sorted by priority. Claude (priority 4) runs first; Gemini (priority 5) is the fallback. Each provider returns an `OCR_Response` with plain text, Markdown, and Gutenberg block HTML.
5. Public endpoints (`/extraction`, `/text`) are intercepted early in `parse_request` by `Rewrite_Rules`, which delegates Markdown serving to `prc-markdown-for-agents` when available.
6. `Content_Discovery` adds `<link rel="alternate">` tags, JSON-LD `DigitalDocument` structured data, `/llms.txt`, and `robots.txt` Allow rules to make extractions discoverable by crawlers and LLMs.

The OCR layer uses a layered namespace under `PRC\Platform\PDF_Extraction\OCR`:

| Layer          | Namespace            | Responsibility                                                        |
| -------------- | -------------------- | --------------------------------------------------------------------- |
| Domain         | `OCR\Domain`         | `OCR_Request`, `OCR_Response`, typed exceptions                       |
| Application    | `OCR\Application`    | `OCR_Orchestrator`, quality validation, page analysis, result merging |
| Infrastructure | `OCR\Infrastructure` | HTTP client abstraction, file base64 encoder                          |
| Providers      | `OCR\Providers`      | Claude, Gemini, WP AI concrete implementations                        |

### Key Files

| Path                                                         | Purpose                                                                                                             |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- |
| `prc-pdf-extraction.php`                                     | Plugin entry point; defines constants, registers activation hooks                                                   |
| `includes/class-bootstrap.php`                               | Loads all dependencies and wires up module instances                                                                |
| `includes/class-content-type.php`                            | Registers the `pdf_extraction` CPT and its 13 post meta fields                                                      |
| `includes/class-extraction-service.php`                      | Core logic: resolve PDF path, run OCR, save extraction post                                                         |
| `includes/class-action-scheduler-handler.php`                | Async job queue; fires `prc_pdf_extraction_process_complete` and `prc_pdf_extraction_process_failed` hooks          |
| `includes/class-rest-api.php`                                | `POST /convert` and `GET /status` endpoints for block editor                                                        |
| `includes/class-rewrite-rules.php`                           | Intercepts `/{slug}/extraction` and `/{slug}/text` URL paths                                                        |
| `includes/class-content-discovery.php`                       | Alternate link tags, JSON-LD, `/llms.txt`, robots.txt rules                                                         |
| `includes/class-markdown-for-agents-integration.php`         | Registers CPT support for `prc-markdown-for-agents`; enriches YAML frontmatter                                      |
| `includes/class-wp-cli-commands.php`                         | Single-post CLI commands: `process`, `test-file`, `validate`, `list-extractions`, `list-providers`, `estimate-cost` |
| `includes/class-bulk-cli-command.php`                        | `bulk-process` VIP CLI command; cursor-paginated, defaults to dry-run                                               |
| `includes/ocr/application/class-ocr-orchestrator.php`        | Tries providers by priority; returns first response that passes quality validation                                  |
| `includes/ocr/providers/class-claude-provider.php`           | Anthropic API integration; uses Files API to upload once and cache `file_id` on attachment meta                     |
| `includes/ocr/providers/class-gemini-provider.php`           | Google Gemini API integration; page-grouped extraction with validation                                              |
| `includes/ocr/providers/trait-shared-extraction-prompts.php` | Markdown and Gutenberg block extraction prompts shared by Claude and Gemini                                         |
| `includes/templates/template-extraction.php`                 | Fallback Markdown template (used when `prc-markdown-for-agents` is inactive)                                        |
| `includes/templates/template-text.php`                       | Plain-text response template for `/text` endpoint                                                                   |

## Hooks & Filters

| Hook                                   | Type   | Description                                                                                       |
| -------------------------------------- | ------ | ------------------------------------------------------------------------------------------------- |
| `prc_pdf_extraction_post_type`         | filter | Override the CPT slug (default: `pdf_extraction`)                                                 |
| `prc_pdf_extraction_url_slug`          | filter | Override the public URL segment (default: `extraction`)                                           |
| `prc_pdf_extraction_labels`            | filter | Override CPT label strings                                                                        |
| `prc_pdf_extraction_claude_model`      | filter | Override the Claude model at runtime (default: `claude-sonnet-4-6`)                               |
| `prc_pdf_extraction_gemini_model`      | filter | Override the Gemini model at runtime                                                              |
| `prc_pdf_extraction_claude_max_tokens` | filter | Override Claude `max_tokens` (default: 16384)                                                     |
| `prc_pdf_extraction_ocr_timeout`       | filter | Override API request timeout in seconds (default: 120)                                            |
| `prc_pdf_extraction_provider_priority` | filter | Override a provider's priority; receives `($priority, $provider_name)`                            |
| `prc_pdf_extraction_markdown_prompt`   | filter | Replace the Markdown extraction prompt sent to Claude and Gemini                                  |
| `prc_pdf_extraction_gutenberg_prompt`  | filter | Replace the Gutenberg block extraction prompt                                                     |
| `prc_pdf_extraction_process_complete`  | action | Fires after a successful async extraction: `($extraction_id, $post_id, $attachment_id, $user_id)` |
| `prc_pdf_extraction_process_failed`    | action | Fires after a failed async extraction: `($post_id, $attachment_id, $user_id, $error_message)`     |

The plugin also filters `prc_markdown_for_agents_frontmatter` to inject OCR metadata (provider, confidence, extraction date, source URL, parent article link) into the YAML frontmatter of Markdown responses.

## REST API

Both endpoints require `edit_post` capability on the target post.

| Method | Endpoint                                 | Description                                                                                                                                          |
| ------ | ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `POST` | `/wp-json/prc-pdf-extraction/v1/convert` | Schedule an async extraction job. Body: `post_id`, `attachment_id`. Returns `job_id` and `status`. Idempotent — returns `pending` if already queued. |
| `GET`  | `/wp-json/prc-pdf-extraction/v1/status`  | Check extraction status. Params: `post_id`, `attachment_id`. Returns `status` (`none`, `pending`, `complete`) and `extraction_id`.                   |

## WP-CLI Commands

```bash
# Process a single post's topline PDF
wp prc-pdf-extraction process --post_id=123

# Force reprocess (overwrites existing extraction)
wp prc-pdf-extraction process --post_id=123 --force

# Test with a local PDF file without saving to the database
wp prc-pdf-extraction test-file --file=/path/to/test.pdf

# Preview bulk scheduling across all posts with reportMaterials (dry-run is default)
wp prc-pdf-extraction bulk-process

# Enqueue bulk extraction jobs
wp prc-pdf-extraction bulk-process --dry-run=false

# Resume a bulk run after interruption from a specific post ID
wp prc-pdf-extraction bulk-process --dry-run=false --start-id=12345

# List configured OCR providers and their availability
wp prc-pdf-extraction list-providers

# Check extraction status for a post
wp prc-pdf-extraction list-extractions --post_id=123

# Validate an existing extraction post
wp prc-pdf-extraction validate --post_id=456

# Estimate OCR cost before running
wp prc-pdf-extraction estimate-cost --post_id=123
```

### Provider-specific options

```bash
# Force a specific OCR provider (bypasses orchestrator priority)
wp prc-pdf-extraction process --post_id=123 --provider=claude
wp prc-pdf-extraction process --post_id=123 --provider=gemini

# Override the Gemini model for a single test run
wp prc-pdf-extraction test-file --file=/path/to/test.pdf --provider=gemini --model=gemini-3-flash-preview

# Show Markdown output instead of plain text in test-file
wp prc-pdf-extraction test-file --file=/path/to/test.pdf --show-markdown
```

## Stored Post Meta

Each `pdf_extraction` post stores the following meta (all exposed via REST):

| Key                            | Type    | Description                                           |
| ------------------------------ | ------- | ----------------------------------------------------- |
| `_pdf_source_attachment_id`    | integer | WP attachment ID of the source PDF                    |
| `_pdf_source_url`              | string  | Source PDF URL from `reportMaterials`                 |
| `_pdf_source_label`            | string  | Label from `reportMaterials`                          |
| `_ocr_provider_used`           | string  | Provider that produced the extraction (e.g. `claude`) |
| `_ocr_confidence_score`        | float   | Confidence score 0–1                                  |
| `_extraction_date`             | string  | MySQL timestamp of the extraction                     |
| `_extraction_version`          | string  | Plugin version used                                   |
| `_extracted_text_plain`        | string  | Plain text output                                     |
| `_extracted_text_markdown`     | string  | Markdown output                                       |
| `_validation_status`           | string  | `passed`, `failed`, or `warning`                      |
| `_validation_issues`           | string  | JSON-encoded array of issue strings                   |
| `_processing_cost_usd`         | float   | Estimated API cost in USD                             |
| `_character_count`             | integer | Total character count of extracted text               |
| `_extraction_duration_seconds` | float   | Time taken for the extraction in seconds              |

## Troubleshooting

### Extraction fails with "file not found (may have expired)"

**Symptom**: Claude provider throws an extraction error referencing an expired file. Logs show `Claude API: file not found (may have expired)`.

**Cause**: Claude's Files API caches uploaded PDFs for approximately 30 days. The `file_id` is stored on the WP attachment as `_claude_file_id`. When the file expires on Anthropic's end, subsequent calls referencing the cached `file_id` return a 404.

**Fix**: The provider automatically retries once by deleting the stale `_claude_file_id` meta and re-uploading the file. If you need to force a manual reset, delete the `_claude_file_id` post meta from the attachment, then trigger a new extraction.

### No OCR providers available

**Symptom**: `wp prc-pdf-extraction list-providers` shows no providers, or extraction returns `no_providers` WP_Error.

**Cause**: Neither `PRC_PLATFORM_ANTHROPIC_API_KEY` nor `PRC_PLATFORM_GOOGLE_API_KEY` is defined in `vip-config/keys-and-tokens.php`.

**Fix**: Add at least one key to `vip-config/keys-and-tokens.php`. Claude is the preferred primary provider; Gemini is a capable fallback.

### Bulk process enqueues no jobs

**Symptom**: `bulk-process` reports 0 jobs scheduled even though posts exist with topline materials.

**Cause**: Action Scheduler is not active, or posts have `reportMaterials` meta but no entries with `type === 'topline'`, or all toplines already have extractions (and `--force` was not passed).

**Fix**: Confirm Action Scheduler is loaded (`function_exists('as_enqueue_async_action')`). Run with `--dry-run=false --force` to bypass the existing-extraction check.

### Local dev PDF downloads pull from production

**Symptom**: During local development, PDF downloads succeed but the file comes from `www.pewresearch.org` rather than the local environment.

**Cause**: `Extraction_Service::download_remote_pdf()` detects `.vipdev.lndo.site` URLs and swaps them for the production URL. This is intentional — local environments typically don't have the uploaded PDF files locally.

**Fix**: Pass `--file=/path/to/local.pdf` to `wp prc-pdf-extraction process` to use a local file and skip the download entirely.
