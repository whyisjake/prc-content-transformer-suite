# Plain Text Format Specification

## Output Format
Clean, readable plain text with no markup, no HTML, no Markdown syntax.

## Rules

1. **No formatting markup.** Remove all Markdown syntax (headers become plain lines, bold/italic markers removed, links become inline text with URL in parentheses).
2. **Preserve structure.** Use blank lines between paragraphs. Use indentation for nested content where appropriate.
3. **Links.** Convert `[text](url)` to `text (url)`.
4. **Images.** Convert image references to `[Image: alt text]` on its own line.
5. **Tables.** Convert to aligned plain text columns or a readable list format.
6. **Lists.** Use `- ` for unordered items and `1. ` for ordered items. Preserve nesting with indentation.
7. **Block quotes.** Prefix each line with `> `.
8. **Charts and data visualizations.** Describe the chart type and include any available data in a readable tabular format.
9. **Headings.** Render as UPPERCASE text on its own line, followed by a blank line.
10. **Horizontal rules.** Render as a line of dashes: `---`.

## Content Fidelity

- ALL factual content, statistics, data points, and citations must be preserved exactly.
- Do not summarize, paraphrase, or omit any content.
- Preserve the original reading order.

## Example

Input (Markdown):
```
# Key Findings

**72% of Americans** say they trust [local news](https://example.com/study) more than national outlets.

| Source | Trust Level |
|--------|------------|
| Local  | 72%        |
| National | 43%      |
```

Output (Plain Text):
```
KEY FINDINGS

72% of Americans say they trust local news (https://example.com/study) more than national outlets.

Source       Trust Level
Local        72%
National     43%
```
