# Editorial User Guide: Topline PDF Extraction

This guide is for Pew Research Center editors and researchers working with topline survey PDFs in the WordPress admin.

## What This Feature Does

The Topline PDF Extraction feature automatically reads topline survey PDFs attached to report articles and converts them to structured text. Each extraction is stored as a web page, making topline content:

- Searchable by researchers and the public
- Accessible to AI assistants via standardized Markdown and plain text endpoints
- Discoverable by search engines through structured data

## How Extraction Works

Extraction is triggered automatically or manually when a topline PDF is attached to a report via `reportMaterials`. The system:

1. Reads the PDF using the Claude AI provider (with Gemini as a fallback)
2. Stores the extracted text as a `pdf_extraction` child post linked to the parent report
3. Makes the content available at public URL endpoints

## Triggering an Extraction

### From the Block Editor (Manual)

1. Open the report article that has a topline PDF attached in `reportMaterials`
2. Look for the **PDF Extraction** panel in the block editor sidebar
3. Click **Extract Topline** to queue the job
4. The extraction runs asynchronously — refresh in 1–2 minutes to see results
5. A status indicator shows `pending`, `complete`, or if there was a failure

### Checking Extraction Status

After triggering, use the **Extraction Status** panel in the block editor sidebar to see:

- **Status**: `none` (not yet extracted), `pending` (queued), or `complete`
- **Extraction ID**: links to the stored extraction post if complete

## Reading Extraction Results in WP Admin

When an extraction is complete, a `pdf_extraction` post is created as a child of the parent report. To view it:

1. Go to **PDF Extractions** in the WordPress admin menu (or navigate via the parent post)
2. Find the extraction linked to your report

### What the Meta Boxes Show

**PDF Source Information** (main column, top):
- **Attachment ID**: the WP media library attachment for the source PDF
- **Source URL**: direct link to the PDF file
- **Label**: the `reportMaterials` label (e.g., "Topline")

**OCR Results** (main column):
- **OCR Provider**: which AI processed the PDF (`claude`, `gemini`, or `wp-ai`)
- **Confidence Score**: 0–1 scale; above 0.85 is excellent, 0.7–0.85 is good, below 0.7 may need review
- **Character Count**: total characters extracted — very low counts may indicate a scanned or image-only PDF
- **Plain Text**: the raw extracted text (read-only preview)
- **Markdown**: the Markdown-formatted version with headers and structure preserved

**Validation Results** (sidebar):
- **Validation Status**: `passed`, `warning`, or `failed`
- **Issues**: specific problems found (e.g., "confidence below threshold", "low character count")

**Processing Metadata** (sidebar):
- **Provider Used**: confirms which AI ran the extraction
- **Extraction Date**: when it ran
- **Processing Cost**: estimated API cost in USD
- **Duration**: how long the extraction took

## Accessing Extracted Content via URLs

Once extracted, topline content is available at predictable public URLs:

| Format | URL Pattern | Use Case |
|--------|-------------|----------|
| Markdown | `/{report-slug}/extraction` | Researchers, AI tools, LLMs |
| Plain text | `/{report-slug}/text` | Simple text access, citation tools |

For example, if the report lives at `pewresearch.org/politics/2024/01/15/american-views-on-policy`, the topline extraction is at:
- `pewresearch.org/politics/2024/01/15/american-views-on-policy/extraction`
- `pewresearch.org/politics/2024/01/15/american-views-on-policy/text`

These URLs are stable and can be shared or bookmarked.

## Interpreting Extraction Quality

### Confidence Score Guide

| Score | Interpretation | Action |
|-------|---------------|--------|
| 0.85–1.0 | Excellent | No action needed |
| 0.70–0.85 | Good | Spot-check a few questions |
| 0.50–0.70 | Fair | Review extraction against PDF |
| Below 0.50 | Poor | Consider re-triggering; contact DevOps if repeated |

### Validation Statuses

- **passed**: Extraction met all quality thresholds — character count, confidence, and structure checks passed
- **warning**: Extraction completed but one or more checks raised a concern (see Issues list); content is likely usable but worth reviewing
- **failed**: Extraction did not meet quality standards; the content may be incomplete or unreliable

## Common Issues

### "The extraction looks incomplete"

The PDF may be a scanned image rather than text-based. The Claude provider handles image PDFs, but very low-resolution scans may produce lower confidence scores. Check the Confidence Score — if below 0.70, flag it to the DevOps team to trigger a re-extraction.

### "I triggered an extraction but the status still shows 'pending'"

Async jobs run via Action Scheduler, which processes in the background. Wait 2–5 minutes and refresh. If the status hasn't changed after 10 minutes, contact the engineering team — the queue may need attention.

### "The extraction URL returns a 404"

The extraction post may not be published yet, or the report's URL slug may have changed. Check the extraction post status in WP Admin (it should be `published`) and confirm the parent report URL hasn't changed.

### "I want to re-run an extraction to get a fresher result"

Contact the DevOps or engineering team. Re-extractions require running `wp prc-pdf-extraction process --post_id=<ID> --force` from the WP-CLI, which is a developer operation.

## Content Discovery for AI and Researchers

The plugin automatically makes topline content discoverable:

- **LLMs.txt**: Listed in `/llms.txt` so AI assistants can find topline content
- **Sitemap**: Included in the XML sitemap for search engine indexing
- **JSON-LD**: Structured data (`DigitalDocument`) embedded in the parent report page
- **Link Tags**: `<link rel="alternate">` tags point to extraction and text endpoints

This means AI tools and researchers who discover a report at pewresearch.org can also find the full topline text without any additional steps.

## Getting Help

For editorial questions about extraction quality or content: contact the Pew digital team.

For technical issues (failed extractions, access problems, re-runs): contact the WordPress VIP engineering team or open a support ticket referencing the plugin `prc-pdf-extraction`.
