<?php
/**
 * Page Analyzer
 *
 * Pre-scans a PDF with a single Gemini Flash Thinking call to detect tables
 * that span across page boundaries, then returns optimal page groupings for extraction.
 * Uses the Gemini Files API (file_uri) — no GhostScript or local image conversion.
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Application;

use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Extraction_Failed_Exception;
use PRC\Platform\PDF_Extraction\OCR\Providers\Gemini_Provider;

/**
 * Analyzes a PDF to detect table continuations and build page groups.
 */
class Page_Analyzer {

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
	 * Analyze the PDF and return page groupings plus page count.
	 *
	 * Makes a single API call that returns both page count and per-page
	 * continuation flags. Each group is an array of 0-based page indices
	 * that should be processed together because a table spans across them.
	 *
	 * Example return: ['groups' => [[0], [1, 2], [3], [4, 5, 6]], 'page_count' => 7]
	 *
	 * @param string $file_uri URI of the PDF in the Gemini Files API.
	 * @return array{groups: int[][], page_count: int}
	 */
	public function analyze( string $file_uri ): array {
		$default = array(
			'groups'     => array( array( 0 ) ),
			'page_count' => 1,
		);

		if ( ! $this->gemini->is_available() ) {
			return $default;
		}

		$flags = $this->scan_pdf( $file_uri );
		if ( empty( $flags ) ) {
			throw new Extraction_Failed_Exception(
				'Page analyzer could not parse PDF structure. Falling back to legacy extraction.'
			);
		}

		$page_count = count( $flags );
		$groups     = $this->build_groups( $flags );

		return array(
			'groups'     => $groups,
			'page_count' => $page_count,
		);
	}

	/**
	 * Ask Gemini to analyze the entire PDF for table continuations in one call.
	 *
	 * @param string $file_uri URI of the PDF in the Gemini Files API.
	 * @return array<int, array{continues_to_next: bool, continues_from_previous: bool}> 0-based page index => flags.
	 */
	private function scan_pdf( string $file_uri ): array {
		$prompt = <<<'PROMPT'
Analyze this Pew Research Center survey topline PDF. For each page, determine:
1. Does a data table appear to be cut off at the BOTTOM of the page (rows continue onto the next page)?
2. Does a data table appear to continue from a PREVIOUS page at the TOP (no table header, rows start immediately)?

Reply with ONLY a JSON object, no other text:
{
  "page_count": <total number of pages>,
  "pages": [
    {"page": 1, "continues_to_next": true/false, "continues_from_previous": true/false},
    {"page": 2, "continues_to_next": true/false, "continues_from_previous": true/false},
    ...
  ]
}

Use 1-based page numbers in the "page" field. Include every page in the PDF.
PROMPT;

		$file_parts = array(
			array(
				'file_data' => array(
					'mime_type' => 'application/pdf',
					'file_uri'  => $file_uri,
				),
			),
		);

		$prescan_timeout = (int) apply_filters( 'prc_pdf_extraction_prescan_timeout', 120 );
		$config_overrides = array(
			'temperature'     => 0.0,
			'maxOutputTokens' => 4096,
			'thinkingConfig'  => $this->gemini->build_prescan_thinking_config(),
			'timeout'         => $prescan_timeout,
		);

		try {
			$result = $this->gemini->call_api( $prompt, $file_parts, $config_overrides );
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'prc-pdf-extraction: page analyzer PDF pre-scan failed: ' . $e->getMessage() );
			return array();
		}

		$text = $result['text'] ?? '';
		if ( empty( $text ) ) {
			return array();
		}

		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```\s*$/', '', $text );

		$parsed = json_decode( trim( $text ), true );
		if ( ! is_array( $parsed ) || empty( $parsed['pages'] ) ) {
			return array();
		}

		$flags = array();
		foreach ( $parsed['pages'] as $item ) {
			if ( ! isset( $item['page'] ) ) {
				continue;
			}
			$idx = (int) $item['page'] - 1; // 1-based to 0-based.
			if ( $idx < 0 ) {
				continue;
			}
			$flags[ $idx ] = array(
				'continues_to_next'       => ! empty( $item['continues_to_next'] ),
				'continues_from_previous' => ! empty( $item['continues_from_previous'] ),
			);
		}

		// Sort by index and fill gaps with defaults.
		ksort( $flags, SORT_NUMERIC );
		$max_idx = empty( $flags ) ? -1 : max( array_keys( $flags ) );
		$default_flag = array(
			'continues_to_next'       => false,
			'continues_from_previous' => false,
		);
		for ( $i = 0; $i <= $max_idx; $i++ ) {
			if ( ! isset( $flags[ $i ] ) ) {
				$flags[ $i ] = $default_flag;
			}
		}
		ksort( $flags, SORT_NUMERIC );

		return $flags;
	}

	/**
	 * Build page groups from per-page continuation flags.
	 *
	 * @param array<int, array{continues_to_next: bool, continues_from_previous: bool}> $flags 0-based index => flags.
	 * @return int[][]
	 */
	private function build_groups( array $flags ): array {
		$groups        = array();
		$current_group = array();

		foreach ( $flags as $index => $flag ) {
			if ( empty( $current_group ) ) {
				$current_group[] = $index;
			} elseif ( $flag['continues_from_previous'] || $flags[ $index - 1 ]['continues_to_next'] ) {
				$current_group[] = $index;
			} else {
				$groups[]        = $current_group;
				$current_group   = array( $index );
			}
		}

		if ( ! empty( $current_group ) ) {
			$groups[] = $current_group;
		}

		return $groups;
	}
}
