<?php
/**
 * WP AI Provider for PRC PDF Extraction
 *
 * Uses the WordPress AI Client (wp-ai-client) for model-agnostic PDF extraction.
 * Supports any configured provider (Google, Anthropic, OpenAI, etc.) with PDF/document understanding.
 *
 * @package PRC\Platform\PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Providers;

use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Authentication_Exception;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Extraction_Failed_Exception;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Rate_Limit_Exception;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

/**
 * WP AI Provider class
 */
class WP_AI_Provider implements OCR_Provider_Interface {

	/**
	 * Provider priority (lower = higher priority)
	 *
	 * @var int
	 */
	private int $priority = 5;

	/**
	 * Get provider name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wp-ai';
	}

	/**
	 * Get provider priority
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return apply_filters( 'prc_pdf_extraction_provider_priority', $this->priority, $this->get_name() );
	}

	/**
	 * Check if provider is available
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		try {
			$builder = wp_ai_client_prompt( 'test' );
			if ( is_wp_error( $builder ) ) {
				return false;
			}
			$prompt = $builder->using_temperature( 0.1 );
			return $prompt->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Estimate cost for processing a file
	 *
	 * Rough heuristic: 1MB PDF ≈ 10 pages ≈ $0.02 per call.
	 * Actual cost depends on which model the AI Client selects.
	 *
	 * @param string $file_path Path to PDF file.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( string $file_path ): float {
		$file_size = filesize( $file_path );
		$pages     = max( 1, ceil( $file_size / 100000 ) );
		return $pages * 0.002;
	}

	/**
	 * Extract text from PDF using the WordPress AI Client
	 *
	 * Makes two API calls: one for Gutenberg block HTML (post_content) and one for
	 * markdown (_extracted_text_markdown). Plain text is derived from markdown.
	 *
	 * @param OCR_Request $request The OCR request.
	 * @return OCR_Response The extraction response.
	 * @throws Authentication_Exception If API credentials are invalid.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	public function extract_text( OCR_Request $request ): OCR_Response {
		if ( ! $this->is_available() ) {
			throw new Authentication_Exception( 'WP AI Client not configured or no providers available. Configure credentials at Settings > AI Credentials.' );
		}

		$file_path = $request->get_file_path();

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Extraction_Failed_Exception( 'PDF file not found or not readable: ' . $file_path );
		}

		$timeout = (int) apply_filters( 'prc_pdf_extraction_ocr_timeout', 120 );
		$options = RequestOptions::fromArray( array( RequestOptions::KEY_TIMEOUT => (float) $timeout ) );

		// Gutenberg call - block HTML for post_content
		$gutenberg_prompt = $this->build_gutenberg_prompt();
		$gutenberg_text   = $this->call_ai( $file_path, $gutenberg_prompt, $options, 'Gutenberg block HTML' );

		// Markdown call - for _extracted_text_markdown and derived plain text
		$markdown_prompt = $this->build_markdown_prompt();
		$markdown_text   = $this->call_ai( $file_path, $markdown_prompt, $options, 'markdown' );

		// Derive plain text from markdown
		$plain_text = $this->markdown_to_plain( $markdown_text );

		// Confidence based on Gutenberg output (was_truncated not available from generate_text)
		$confidence = $this->calculate_confidence( $gutenberg_text, false );

		// Combined cost for both calls
		$cost = $this->estimate_cost( $file_path ) * 2;

		return new OCR_Response(
			true,
			$plain_text,
			$markdown_text,
			$gutenberg_text,
			$confidence,
			$this->get_name(),
			$cost,
			array()
		);
	}

	/**
	 * Make a single AI Client call with prompt + file.
	 *
	 * @param string         $file_path Path to PDF file.
	 * @param string         $prompt    The extraction prompt text.
	 * @param RequestOptions $options   Request options (e.g. timeout).
	 * @param string         $label     Label for error messages.
	 * @return string Extracted text.
	 * @throws Authentication_Exception If API credentials are invalid.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	private function call_ai( string $file_path, string $prompt, RequestOptions $options, string $label ): string {
		try {
			$builder = wp_ai_client_prompt( $prompt );
			if ( is_wp_error( $builder ) ) {
				throw new \Exception( $builder->get_error_message() );
			}

			$text = $builder
				->with_file( $file_path, 'application/pdf' )
				->using_temperature( 0.1 )
				->using_model_preference( 'claude-opus-4-7', 'claude-sonnet-4-6', 'gemini-3-flash-preview' )
				->using_request_options( $options )
				->generate_text();

			if ( is_wp_error( $text ) ) {
				throw new \Exception( $text->get_error_message() );
			}

			return trim( (string) $text );
		} catch ( \Exception $e ) {
			$this->translate_exception( $e, $label );
		}
	}

	/**
	 * Translate AI Client exceptions to OCR domain exceptions.
	 *
	 * @param \Exception $e     The caught exception.
	 * @param string     $label Label for error context.
	 * @return never
	 * @throws Authentication_Exception If API credentials are invalid.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	private function translate_exception( \Exception $e, string $label ): void {
		$message = strtolower( $e->getMessage() );

		if ( preg_match( '/\b(401|403|unauthorized|authentication|invalid.*key|invalid api key)\b/', $message ) ) {
			throw new Authentication_Exception( 'AI provider authentication failed: ' . $e->getMessage() );
		}

		if ( preg_match( '/\b(429|rate limit|quota exceeded|too many requests)\b/', $message ) ) {
			throw new Rate_Limit_Exception( 'AI provider rate limit exceeded: ' . $e->getMessage() );
		}

		throw new Extraction_Failed_Exception(
			sprintf( 'No %s extracted from PDF: %s', $label, $e->getMessage() )
		);
	}

	/**
	 * Build the markdown extraction prompt for topline documents.
	 *
	 * @return string The markdown prompt.
	 */
	private function build_markdown_prompt(): string {
		return <<<'PROMPT'
You are extracting text from a Pew Research Center survey topline document. This is a PDF containing survey questions, response options, and percentage data.

Please extract ALL text from this document and format it as clean, well-structured Markdown following these rules:

1. **Survey Questions**: Format as headers (## Q1., ## Q2., etc.)
2. **Response Options**: Format as a table with columns for the response text and percentage values
3. **Sample Sizes**: Keep n= values inline with their context
4. **Instructions/Notes**: Format as blockquotes (> text)
5. **Section Headers**: Use appropriate heading levels (# for main sections, ## for subsections)
6. **Preserve ALL data**: Every percentage, every response option, every note must be included
7. **Tables**: Use proper Markdown table syntax with headers

Extract the complete content maintaining the logical structure of the survey document. Do not summarize or omit any content.
PROMPT;
	}

	/**
	 * Build Gutenberg block HTML extraction prompt
	 *
	 * @return string The Gutenberg prompt.
	 */
	private function build_gutenberg_prompt(): string {
		return <<<'PROMPT'
Extract ALL text from this Pew Research Center survey topline PDF and format it as WordPress Gutenberg block HTML.

BLOCK FORMATS TO USE:

1. **Headings** - Use for survey questions (Q1., Q2., etc.) and section titles:
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Question text here</h2>
<!-- /wp:heading -->

2. **Tables** - Use prc-block/table for survey response data with percentages:
<!-- wp:prc-block/table -->
<figure class="wp-block-prc-block-table"><table>
<thead><tr><th>Response</th><th>%</th></tr></thead>
<tbody>
<tr><td>Option 1</td><td>45</td></tr>
<tr><td>Option 2</td><td>32</td></tr>
</tbody>
</table></figure>
<!-- /wp:prc-block/table -->

3. **Paragraphs** - Use for descriptive text, instructions, and notes:
<!-- wp:paragraph -->
<p>Text content here</p>
<!-- /wp:paragraph -->

4. **Blockquotes** - Use for survey instructions, interviewer notes, or special annotations:
<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>Note or instruction text</p></blockquote>
<!-- /wp:quote -->

TABLE RULES:
- Use colspan attribute when headers span multiple columns (e.g., country groupings, demographic categories)
- Use rowspan attribute when row labels span multiple data rows (e.g., country names with sub-categories)
- Keep all percentage data in separate cells
- Use <thead> for header rows and <tbody> for data rows
- Sample sizes (n=1,234) can be in their own column or as a note below the table

IMPORTANT:
- Extract ALL content from ALL pages - do not summarize or omit anything
- Preserve the exact structure and hierarchy of the survey document
- Every percentage, every response option, every sample size must be included
- Output ONLY the Gutenberg block HTML, nothing else
PROMPT;
	}

	/**
	 * Calculate confidence score based on response quality signals.
	 *
	 * @param string $text          The extracted Gutenberg block HTML.
	 * @param bool   $was_truncated Whether the response was truncated (not available from AI Client).
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

		$table_count = substr_count( $text, 'wp:prc-block/table' );
		if ( 0 === $table_count ) {
			$confidence -= 0.10;
			$issues[]    = 'no_tables_found';
		}

		$question_count = preg_match_all( '/\bQ\d+[a-z]?\./', $text );
		if ( 0 === $question_count ) {
			$confidence -= 0.05;
			$issues[]    = 'no_questions_found';
		}

		if ( class_exists( 'WP_CLI' ) && ! empty( $issues ) ) {
			\WP_CLI::line( sprintf( 'Confidence adjusted: %.2f (issues: %s)', $confidence, implode( ', ', $issues ) ) );
		}

		return max( 0.0, min( 1.0, $confidence ) );
	}

	/**
	 * Convert markdown to plain text
	 *
	 * @param string $markdown Markdown text.
	 * @return string Plain text.
	 */
	private function markdown_to_plain( string $markdown ): string {
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

		return trim( $plain );
	}
}
