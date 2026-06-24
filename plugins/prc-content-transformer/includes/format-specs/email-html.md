# Email HTML Format Specification

## Overview

Transform content into email-safe HTML compatible with Mailchimp templates and major email clients (Gmail, Outlook, Apple Mail, Yahoo Mail).

## Structural Rules

1. **Table-based layout.** Use `<table>` elements for layout, not CSS flexbox/grid. Email clients have inconsistent CSS support.
2. **Inline CSS only.** All styles must be inline via `style=""` attributes. No `<style>` blocks, no external stylesheets.
3. **Maximum width: 600px.** The outer container table should be 600px wide, centered.
4. **UTF-8 encoding.** Content must be UTF-8 compatible.

## HTML Structure

```html
<table
	width="600"
	cellpadding="0"
	cellspacing="0"
	border="0"
	align="center"
	style="max-width:600px;width:100%;margin:0 auto;font-family:Georgia,'Times New Roman',Times,serif;"
>
	<tr>
		<td style="padding:20px;">
			<!-- Content sections go here -->
		</td>
	</tr>
</table>
```

## Content Mapping

### Typography precedence

1. **Explicit typography:** Content wrapped in `{{PRC_EMAIL_TYPOGRAPHY ...}}...{{/PRC_EMAIL_TYPOGRAPHY}}` must use the annotated CSS properties on the **single** inner block only (the next heading, paragraph, or list item). Merge annotation attributes into that element's `style=""` with the same property names (e.g. `font-family`, `font-size`, `line-height`). Other blocks keep the defaults below.
2. **Newsletter defaults:** Use the serif heading and body stacks in the examples below when there is no typography annotation.
3. **Shell / body fallback:** Ignore bare `<body>` font-family; rely on inline styles per element.

### Spacers

When the source contains a spacer token, insert email-safe vertical space using a presentation table row (not margin on a div):

`{{PRC_EMAIL_SPACER height="25px"}}` →

```html
<table
	width="100%"
	cellpadding="0"
	cellspacing="0"
	border="0"
	role="presentation"
>
	<tr>
		<td height="25" style="height:25px;line-height:25px;font-size:0;">
			&nbsp;
		</td>
	</tr>
</table>
```

Use the `height` value from the token for both the HTML `height` attribute and `height`/`line-height` in the `style` attribute.

### Typography annotations

Source may include paired markers with HTML-attribute-style pairs on the opening tag:

```md
{{PRC_EMAIL_TYPOGRAPHY font-family="'franklin-gothic-urw',Verdana,Geneva,sans-serif" font-size="16px" line-height="1.4"}}
Paragraph or heading markdown here.
{{/PRC_EMAIL_TYPOGRAPHY}}
```

- Convert the wrapped markdown block to the same table-based structure as an unannotated block.
- Apply **all** annotation attributes as inline CSS on the innermost content element (`<p>`, `<h1>`–`<h6>`, or list item `<td>`), merging with any default styles from the examples.
- Remove the `{{PRC_EMAIL_TYPOGRAPHY ...}}` and `{{/PRC_EMAIL_TYPOGRAPHY}}` lines from the output; they must not appear in the final HTML.

### Headings

- `h1`: `<h1 style="font-family:Georgia,'Times New Roman',Times,serif;font-size:28px;line-height:34px;color:#000000;margin:0 0 16px 0;padding:0;">Title</h1>`
- `h2`: `<h2 style="font-family:Georgia,'Times New Roman',Times,serif;font-size:22px;line-height:28px;color:#000000;margin:24px 0 12px 0;padding:0;">Heading</h2>`
- `h3`: `<h3 style="font-family:Georgia,'Times New Roman',Times,serif;font-size:18px;line-height:24px;color:#333333;margin:20px 0 8px 0;padding:0;">Heading</h3>`

### Paragraphs

```html
<p
	style="font-family:Georgia,'Times New Roman',Times,serif;font-size:16px;line-height:26px;color:#333333;margin:0 0 16px 0;"
>
	Paragraph text here.
</p>
```

### Links

```html
<a href="URL" style="color:#2b6dad;text-decoration:underline;">Link text</a>
```

### Images

WordPress source content uses Pandoc-style attributes after a markdown image to indicate alignment and width:

```
![alt](url)                          → default, full content width
![alt](url){.alignleft width=280}    → text wraps to the right of the image
![alt](url){.alignright width=280}   → text wraps to the left of the image
![alt](url){.aligncenter width=400}  → centered, no wrap
![alt](url){.alignwide}              → full 560px content width
![alt](url){.alignfull}              → full 600px (edge-to-edge)
```

Use the `width` value from the annotation when present. Apply the HTML 4 `align` attribute on the wrapping `<table>` — do NOT use CSS `float` (not supported in Outlook).

#### Default / wide / full image (no alignment annotation, or `.alignwide` / `.alignfull`)

```html
<table
	width="100%"
	cellpadding="0"
	cellspacing="0"
	border="0"
	role="presentation"
>
	<tr>
		<td style="padding:16px 0;">
			<img
				src="IMAGE_URL"
				alt="Alt text"
				width="560"
				style="display:block;max-width:100%;height:auto;border:0;"
			/>
		</td>
	</tr>
</table>
```

#### Right-aligned image (`.alignright` — text wraps to the left of the image)

```html
<table
	align="right"
	cellpadding="0"
	cellspacing="0"
	border="0"
	role="presentation"
	style="margin:0 0 16px 16px;"
>
	<tr>
		<td>
			<img
				src="IMAGE_URL"
				alt="Alt text"
				width="280"
				style="display:block;max-width:280px;height:auto;border:0;"
			/>
		</td>
	</tr>
</table>
```

#### Left-aligned image (`.alignleft` — text wraps to the right of the image)

```html
<table
	align="left"
	cellpadding="0"
	cellspacing="0"
	border="0"
	role="presentation"
	style="margin:0 16px 16px 0;"
>
	<tr>
		<td>
			<img
				src="IMAGE_URL"
				alt="Alt text"
				width="280"
				style="display:block;max-width:280px;height:auto;border:0;"
			/>
		</td>
	</tr>
</table>
```

#### Centered image (`.aligncenter` — no text wrap)

```html
<table
	width="100%"
	cellpadding="0"
	cellspacing="0"
	border="0"
	role="presentation"
>
	<tr>
		<td align="center" style="padding:16px 0;">
			<img
				src="IMAGE_URL"
				alt="Alt text"
				width="400"
				style="display:block;max-width:100%;height:auto;border:0;"
			/>
		</td>
	</tr>
</table>
```

### Chart Images

Charts and data visualizations are provided as standard markdown images (`![alt](url)`). Render them exactly like other full-width images — a centered 560px-wide `<img>` inside the same `<table>` wrapper — using the alt text as the `alt` attribute. Do **NOT** attempt to extract or recreate the underlying data as an HTML table.

```html
<table
	width="100%"
	cellpadding="0"
	cellspacing="0"
	border="0"
	role="presentation"
>
	<tr>
		<td style="padding:16px 0;">
			<img
				src="IMAGE_URL"
				alt="Chart alt text"
				width="560"
				style="display:block;max-width:100%;height:auto;border:0;"
			/>
		</td>
	</tr>
</table>
```

### Block Quotes

```html
<table width="100%" cellpadding="0" cellspacing="0" border="0">
	<tr>
		<td style="padding:16px 0 16px 20px;border-left:4px solid #d1d1d1;">
			<p
				style="font-family:Georgia,'Times New Roman',Times,serif;font-size:16px;line-height:26px;color:#555555;font-style:italic;margin:0;"
			>
				Quoted text here.
			</p>
		</td>
	</tr>
</table>
```

### Unordered Lists

```html
<table width="100%" cellpadding="0" cellspacing="0" border="0">
	<tr>
		<td
			style="padding:0 0 8px 20px;font-family:Georgia,'Times New Roman',Times,serif;font-size:16px;line-height:26px;color:#333333;"
		>
			• List item text
		</td>
	</tr>
</table>
```

### Ordered Lists

Use numbered text (1., 2., 3.) with the same table structure as unordered lists.

### Tables (Data)

```html
<table
	width="100%"
	cellpadding="8"
	cellspacing="0"
	border="0"
	style="border-collapse:collapse;margin:16px 0;"
>
	<tr style="background-color:#f2f2f2;">
		<th
			style="font-family:Georgia,'Times New Roman',Times,serif;font-size:14px;font-weight:bold;color:#333333;text-align:left;border-bottom:2px solid #d1d1d1;padding:8px;"
		>
			Header
		</th>
	</tr>
	<tr>
		<td
			style="font-family:Georgia,'Times New Roman',Times,serif;font-size:14px;color:#333333;border-bottom:1px solid #eeeeee;padding:8px;"
		>
			Data
		</td>
	</tr>
</table>
```

### Horizontal Rule

```html
<table width="100%" cellpadding="0" cellspacing="0" border="0">
	<tr>
		<td style="padding:20px 0;">
			<hr style="border:0;border-top:1px solid #d1d1d1;margin:0;" />
		</td>
	</tr>
</table>
```

## Email Client Compatibility

- Do NOT use: `<div>`, CSS `float`, `position`, `flexbox`, `grid`, `background-image` on non-table elements, `margin: auto` on non-table elements.
- DO use: `<table>`, `<tr>`, `<td>`, inline `style`, `align`, `width`, `cellpadding`, `cellspacing`, `border` attributes.
- For Outlook: always include `width` attributes on tables and images.
- For Gmail: keep CSS simple; avoid shorthand properties.

## Content Fidelity

- ALL factual content, statistics, data points, and citations must be preserved exactly.
- Do not summarize, paraphrase, or omit any content.
- Preserve the original reading order.
- All links must be preserved with their original URLs.
- Image URLs must be preserved exactly as provided.

## Output Requirements

- Output ONLY the HTML content (the inner table structure). Do NOT include `<!DOCTYPE>`, `<html>`, `<head>`, or `<body>` tags.
- The output should be the content portion that would be inserted into a Mailchimp template's editable region.
- All styles must be inline.
