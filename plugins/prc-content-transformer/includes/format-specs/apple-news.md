# Apple News Format (ANF) Specification

## Overview
Transform content into Apple News Format JSON (version 1.11). The output must be a valid JSON object conforming to Apple's ANF specification.

## Top-Level Structure

```json
{
  "version": "1.11",
  "identifier": "post-{post_id}",
  "language": "en",
  "title": "Article Title",
  "layout": {
    "columns": 15,
    "width": 1024,
    "margin": 100,
    "gutter": 20
  },
  "documentStyle": {
    "backgroundColor": "#FFFFFF"
  },
  "components": [],
  "componentTextStyles": {},
  "componentLayouts": {},
  "metadata": {
    "excerpt": "Article excerpt or summary"
  }
}
```

## Component Roles

Each component is a JSON object with at minimum a `role` and content fields. Common roles:

### Text Components
- `body`: Main article text. Use `format: "html"` for rich text.
- `heading1` through `heading6`: Section headings.
- `intro`: Sub-headline or deck text, placed after the title.
- `byline`: Author attribution line.
- `quote`: Block quotes and pull quotes.
- `caption`: Image or figure captions. **Must always be a child component inside a `container` or `figure` — never a top-level component.** Wrap the media component and its caption together in a `container`.

### Media Components
- `photo`: Images. Requires `URL` field with the image URL.
- `embedwebvideo`: Embedded videos (YouTube, Vimeo, Dailymotion only). Requires `URL` field. **The URL must be the embed URL, not the standard watch/share URL.**
  - YouTube: `https://www.youtube.com/embed/VIDEO_ID` (not `watch?v=`)
  - Vimeo: `https://player.vimeo.com/video/VIDEO_ID` (not `vimeo.com/VIDEO_ID`)
  - Dailymotion: `https://geo.dailymotion.com/player.html?video=VIDEO_ID`

### Container Components
- `container`: Groups nested `components` together. Used for callout boxes, collapsible sections, and wrapping media + caption pairs.

### Interactive Components
- `link_button`: Call-to-action buttons. Requires `text`, `URL`, and can reference named styles.

## Component Structure Examples

### Body Text
```json
{
  "role": "body",
  "text": "<p>72% of Americans say they trust local news.</p>",
  "format": "html",
  "textStyle": "default-body",
  "layout": "body-layout"
}
```

### Heading
```json
{
  "role": "heading2",
  "text": "Key Findings",
  "format": "html",
  "textStyle": "default-heading-2",
  "layout": "body-layout"
}
```

### Image
```json
{
  "role": "photo",
  "URL": "https://example.com/image.jpg",
  "layout": "full-width-image",
  "caption": "Image description"
}
```

### Block Quote
```json
{
  "role": "quote",
  "text": "<p>Quoted text here.</p>",
  "format": "html",
  "textStyle": "default-blockquote-left",
  "layout": "blockquote-layout"
}
```

### Callout / Info Box
```json
{
  "role": "container",
  "layout": "callout-layout-full",
  "style": {
    "backgroundColor": "#f7f7f1",
    "border": {
      "all": { "width": 1, "style": "solid", "color": "#dededf" }
    }
  },
  "components": [
    {
      "role": "body",
      "text": "<p>Callout content here.</p>",
      "format": "html"
    }
  ]
}
```

## Named Styles (componentTextStyles)

Define these standard text styles in `componentTextStyles`:

```json
{
  "default-body": {
    "fontName": "Georgia",
    "fontSize": 18,
    "lineHeight": 32,
    "textColor": "#2a2a2a"
  },
  "default-heading-2": {
    "fontName": "Helvetica-Bold",
    "fontSize": 25,
    "lineHeight": 35,
    "textColor": "#2a2a2a"
  },
  "default-blockquote-left": {
    "fontName": "Helvetica",
    "fontSize": 17,
    "lineHeight": 28,
    "textColor": "#2a2a2a"
  },
  "default-byline": {
    "fontName": "Helvetica-Bold",
    "fontSize": 14,
    "lineHeight": 19,
    "textColor": "#5c5c5c"
  }
}
```

## Named Layouts (componentLayouts)

```json
{
  "body-layout": {
    "columnStart": 0,
    "columnSpan": 15,
    "margin": { "top": 12, "bottom": 12 }
  },
  "full-width-image": {
    "columnStart": 0,
    "columnSpan": 15,
    "margin": { "top": 20, "bottom": 20 }
  },
  "blockquote-layout": {
    "columnStart": 2,
    "columnSpan": 11,
    "margin": { "top": 16, "bottom": 16 }
  },
  "callout-layout-full": {
    "columnStart": 0,
    "columnSpan": 15,
    "margin": { "top": 20, "bottom": 20 },
    "contentInset": { "top": false, "bottom": true, "left": true, "right": true }
  }
}
```

## Conversion Rules

1. **Paragraphs** become `body` components with `format: "html"`. Adjacent paragraphs can be merged into a single component.
2. **Headings** (h1-h6) become the corresponding `heading1`-`heading6` role.
3. **Images** become `photo` components. Use the full-resolution image URL.
4. **Block quotes** become `quote` components.
5. **Lists** (ordered and unordered) should be rendered as HTML within a `body` component.
6. **Tables** are NOT supported in ANF HTML. Convert table content to prose, a numbered or bulleted list, or a structured `body` component. Never emit `<table>`, `<thead>`, `<tbody>`, `<tr>`, `<td>`, or `<th>` tags.
7. **Embedded videos** (YouTube, Vimeo, Dailymotion) become `embedwebvideo` components. Convert the source URL to the embed format (e.g. `youtube.com/embed/ID` not `youtube.com/watch?v=ID`).
8. **Charts and data visualizations** should be described as text in a `body` component or rendered as an image if a static URL is available.
9. **Horizontal rules** can be omitted or rendered as a `divider` component.

## Content Fidelity

- ALL factual content, statistics, data points, and citations must be preserved exactly.
- Do not summarize, paraphrase, or omit any content.
- Preserve the original reading order.
- Image URLs must be preserved exactly as provided.

## Anti-Patterns (Common Mistakes to Avoid)

### ❌ Standalone caption after a container-wrapped photo

**Wrong** — the photo is wrapped in a container, then a caption is emitted as a separate top-level component:
```json
{
  "components": [
    { "role": "container", "components": [{ "role": "photo", "URL": "..." }] },
    { "role": "caption", "text": "Photo caption here." }
  ]
}
```

**Correct** — the caption is a sibling inside the same container:
```json
{
  "components": [
    {
      "role": "container",
      "components": [
        { "role": "photo", "URL": "..." },
        { "role": "caption", "text": "Photo caption here.", "format": "html", "textStyle": "caption-style" }
      ]
    }
  ]
}
```

This applies equally to bare photos: `caption` must **always** be a child component inside a `container`, never a top-level `components` entry.

### ❌ Inline layout objects mixed with named layout strings

**Wrong** — mixing inline layout objects with named layout references:
```json
{ "role": "body", "layout": { "columnStart": 0, "columnSpan": 15 } }
```

**Correct** — always reference a named layout defined in `componentLayouts`:
```json
{ "role": "body", "layout": "body-layout" }
```

Define all layout variations in the top-level `componentLayouts` object. Never embed layout dimensions inline on a component.

### ❌ Wrong image URL key casing

**Wrong** — lowercase `url` or `src` on photo/image components:
```json
{ "role": "photo", "url": "https://example.com/image.jpg" }
{ "role": "photo", "src": "https://example.com/image.jpg" }
```

**Correct** — uppercase `URL` exactly as specified by ANF:
```json
{ "role": "photo", "URL": "https://example.com/image.jpg" }
```

## Output Requirements

- Output ONLY the JSON object. No wrapping, no markdown code fences, no explanation.
- Output compact JSON with no whitespace or indentation between tokens.
- The JSON must be valid and parseable.
- Every component must have a `role` field.
- Text components using rich formatting must set `format: "html"`.
