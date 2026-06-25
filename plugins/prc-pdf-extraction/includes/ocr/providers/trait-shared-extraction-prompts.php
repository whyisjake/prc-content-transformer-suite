<?php
/**
 * Shared extraction prompts and post-processing for OCR providers.
 *
 * Used by Claude and Gemini providers which share identical markdown/Gutenberg
 * prompts, confidence scoring, and markdown-to-plain conversion logic.
 *
 * @package PRC\Platform\PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Providers;

/**
 * Trait providing shared extraction prompts and post-processing methods.
 */
trait Trait_Shared_Extraction_Prompts {

	/**
	 * Build the markdown extraction prompt for PDF documents.
	 *
	 * Filterable via prc_pdf_extraction_markdown_prompt.
	 *
	 * @return string The markdown prompt.
	 */
	private function build_markdown_prompt(): string {
		$default = <<<PROMPT
You are extracting text from a PDF document. The PDF may contain tables, headings, lists, and structured data.

Please extract ALL text from this document and format it as clean, well-structured Markdown following these rules:

1. **Headings**: Use the original label exactly as it appears in the PDF. Use appropriate heading levels (# for main sections, ## for subsections)
2. **Tables**: Format tabular data as Markdown tables with headers
3. **Lists and structure**: Preserve the logical structure of the document
4. **Instructions/Notes**: Format as blockquotes (> text)
5. **Preserve ALL data**: Every piece of content must be included
6. **Hyperlinks**: Preserve all URLs and hyperlinks from the PDF. Format them as Markdown links: [link text](URL)

Extract the complete content maintaining the logical structure. Do not summarize or omit any content.
PROMPT;

		return apply_filters( 'prc_pdf_extraction_markdown_prompt', $default );
	}

	/**
	 * Build Gutenberg block HTML extraction prompt.
	 *
	 * Filterable via prc_pdf_extraction_gutenberg_prompt.
	 *
	 * @return string The Gutenberg prompt.
	 */
	private function build_gutenberg_prompt(): string {
		$default = <<<PROMPT
Extract ALL text from this PDF document and format it as WordPress Gutenberg block HTML.

BLOCK FORMATS TO USE:

1. **Headings** - Use for section titles and labels. Preserve the original label exactly as it appears in the PDF:
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">[Original label from PDF]</h2>
<!-- /wp:heading -->

2. **Tables** - Use prc-block/table for tabular data:
<!-- wp:prc-block/table -->
<figure class="wp-block-prc-block-table"><table>
<thead><tr><th>Column 1</th><th>Column 2</th></tr></thead>
<tbody>
<tr><td>Data</td><td>Data</td></tr>
</tbody>
</table></figure>
<!-- /wp:prc-block/table -->

3. **Paragraphs** - Use for descriptive text and notes:
<!-- wp:paragraph -->
<p>Text content here</p>
<!-- /wp:paragraph -->

4. **Blockquotes** - Use for instructions or annotations:
<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>Note text</p></blockquote>
<!-- /wp:quote -->

IMPORTANT:
- Extract ALL content from ALL pages - do not summarize or omit anything
- Preserve the exact structure and hierarchy of the document
- Preserve all hyperlinks from the PDF as <a href="URL"> tags within the block HTML
- Output ONLY the Gutenberg block HTML, nothing else
PROMPT;

		return apply_filters( 'prc_pdf_extraction_gutenberg_prompt', $default );
	}

	/**
	 * Calculate confidence score based on response quality signals.
	 *
	 * Always validates against Gutenberg patterns since that is the primary output.
	 *
	 * @param string $text          The extracted Gutenberg block HTML.
	 * @param bool   $was_truncated Whether the response was truncated.
	 * @return float Confidence score between 0 and 1.
	 */
	private function calculate_confidence( string $text, bool $was_truncated ): float {
		$confidence = 0.95;
		$issues     = array();

		if ( $was_truncated ) {
			$confidence -= 0.20;
			$issues[]    = 'truncated';
		}

		$min_chars = apply_filters( 'prc_pdf_extraction_min_chars', 1000 );
		if ( strlen( $text ) < $min_chars ) {
			$confidence -= 0.10;
			$issues[]    = 'low_char_count';
		}

		$table_count = substr_count( $text, '<table' );
		if ( 0 === $table_count ) {
			$confidence -= 0.10;
			$issues[]    = 'no_tables_found';
		}

		$heading_count = substr_count( $text, 'wp:heading' );
		if ( 0 === $heading_count ) {
			$confidence -= 0.05;
			$issues[]    = 'no_headings_found';
		}

		if ( class_exists( 'WP_CLI' ) && ! empty( $issues ) ) {
			\WP_CLI::line( sprintf( 'Confidence adjusted: %.2f (issues: %s)', $confidence, implode( ', ', $issues ) ) );
		}

		return max( 0.0, min( 1.0, $confidence ) );
	}

	/**
	 * Convert markdown to plain text.
	 *
	 * @param string $markdown Markdown text.
	 * @return string Plain text.
	 */
	public function markdown_to_plain( string $markdown ): string {
		$plain = $markdown;

		$plain = preg_replace( '/^#{1,6}\s+/m', '', $plain );
		$plain = preg_replace( '/\*\*([^*]+)\*\*/', '$1', $plain );
		$plain = preg_replace( '/\*([^*]+)\*/', '$1', $plain );
		$plain = preg_replace( '/__([^_]+)__/', '$1', $plain );
		$plain = preg_replace( '/_([^_]+)_/', '$1', $plain );
		$plain = preg_replace( '/^>\s+/m', '', $plain );
		$plain = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $plain );
		$plain = preg_replace( '/\|/m', ' ', $plain );
		$plain = preg_replace( '/^[-:]+$/m', '', $plain );
		$plain = preg_replace( '/\n{3,}/', "\n\n", $plain );
		$plain = trim( $plain );

		return $plain;
	}
}
