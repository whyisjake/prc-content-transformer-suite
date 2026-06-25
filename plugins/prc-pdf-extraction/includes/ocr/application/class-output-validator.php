<?php
/**
 * Output Validator
 *
 * Validates merged extraction output against the original PDF using
 * Gemini Flash Thinking, and supports targeted retry on mismatches.
 * Uses the Gemini Files API (file_uri) — no GhostScript or local images.
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Application;

use PRC\Platform\PDF_Extraction\OCR\Providers\Gemini_Provider;

/**
 * Sends the final merged output plus the original PDF back to
 * Gemini for a cross-check, returning structured validation results.
 */
class Output_Validator {

	/**
	 * @var Gemini_Provider
	 */
	private Gemini_Provider $gemini;

	/**
	 * @param Gemini_Provider $gemini Gemini provider instance (for API calls).
	 */
	public function __construct( Gemini_Provider $gemini ) {
		$this->gemini = $gemini;
	}

	/**
	 * Validate the merged extraction output against the original PDF.
	 *
	 * @param string $gutenberg_html The merged Gutenberg block HTML.
	 * @param string $file_uri       URI of the PDF in the Gemini Files API.
	 * @param int    $page_count     Total number of pages in the PDF.
	 * @return array{valid: bool, issues: string[], confidence: float, pages_with_issues: int[]}
	 */
	public function validate( string $gutenberg_html, string $file_uri, int $page_count ): array {
		$default = array(
			'valid'             => true,
			'issues'            => array(),
			'confidence'        => 1.0,
			'pages_with_issues' => array(),
		);

		if ( ! $this->gemini->is_available() || empty( $file_uri ) ) {
			return $default;
		}

		$prompt = $this->build_validation_prompt( $gutenberg_html, $page_count );

		$file_parts = array(
			array(
				'file_data' => array(
					'mime_type' => 'application/pdf',
					'file_uri'  => $file_uri,
				),
			),
		);

		$validation_timeout = (int) apply_filters( 'prc_pdf_extraction_validation_timeout', 120 );
		$config_overrides   = array(
			'temperature'     => 0.0,
			'maxOutputTokens' => 4096,
			'thinkingConfig'  => $this->gemini->build_validation_thinking_config(),
			'timeout'         => $validation_timeout,
		);

		try {
			$result = $this->gemini->call_api( $prompt, $file_parts, $config_overrides );
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'prc-pdf-extraction: output validation API call failed: ' . $e->getMessage() );
			return $default;
		}

		$text = $result['text'] ?? '';
		if ( empty( $text ) ) {
			return $default;
		}

		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```\s*$/', '', $text );

		$parsed = json_decode( trim( $text ), true );
		if ( ! is_array( $parsed ) ) {
			return $default;
		}

		return array(
			'valid'             => ! empty( $parsed['valid'] ),
			'issues'            => isset( $parsed['issues'] ) && is_array( $parsed['issues'] ) ? $parsed['issues'] : array(),
			'confidence'        => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0.0,
			'pages_with_issues' => isset( $parsed['pages_with_issues'] ) && is_array( $parsed['pages_with_issues'] ) ? array_map( 'intval', $parsed['pages_with_issues'] ) : array(),
		);
	}

	/**
	 * Build the validation prompt.
	 *
	 * @param string $gutenberg_html The extraction output.
	 * @param int    $page_count     Total number of pages in the PDF.
	 * @return string
	 */
	private function build_validation_prompt( string $gutenberg_html, int $page_count ): string {
		$max_chars = 100000;
		$excerpt   = strlen( $gutenberg_html ) > $max_chars
			? substr( $gutenberg_html, 0, $max_chars ) . "\n[... TRUNCATED ...]"
			: $gutenberg_html;

		return <<<PROMPT
The PDF above is a Pew Research Center survey topline document with {$page_count} page(s).
The text below is the extracted content in WordPress Gutenberg block HTML format.

EXTRACTED CONTENT:
{$excerpt}

Compare the extracted content against the original PDF. Check for:
1. Missing survey questions (Q1., Q2., etc.)
2. Wrong or missing percentages in data tables
3. Missing table rows or response options
4. Truncated or cut-off content
5. Tables from the original that are completely absent

Reply with ONLY a JSON object:
{
  "valid": true/false,
  "issues": ["description of each issue found"],
  "confidence": 0.0-1.0,
  "pages_with_issues": [0-based page indices where issues were found]
}

If everything matches correctly, reply: {"valid": true, "issues": [], "confidence": 1.0, "pages_with_issues": []}
PROMPT;
	}
}
