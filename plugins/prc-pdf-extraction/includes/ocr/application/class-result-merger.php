<?php
/**
 * Result Merger
 *
 * Combines per-page-group OCR_Response objects into a single unified response.
 *
 * @package PRC\Platform\PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Application;

use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;

/**
 * Merges multiple OCR_Response objects produced from individual page groups
 * into one final response with aggregated confidence and cost.
 */
class Result_Merger {

	/**
	 * Merge an ordered array of OCR_Response objects.
	 *
	 * @param OCR_Response[] $responses Ordered per-group responses.
	 * @return OCR_Response Combined response.
	 */
	public function merge( array $responses ): OCR_Response {
		if ( count( $responses ) === 1 ) {
			return $responses[0];
		}

		$gutenberg_parts = array();
		$markdown_parts  = array();
		$plain_parts     = array();
		$total_cost      = 0.0;
		$total_chars     = 0;
		$weighted_conf   = 0.0;

		foreach ( $responses as $response ) {
			$gutenberg_parts[] = $response->get_gutenberg();
			$markdown_parts[]  = $response->get_markdown();
			$plain_parts[]     = $response->get_text();
			$total_cost       += $response->get_cost();

			$chars = max( 1, $response->get_character_count() );
			$total_chars   += $chars;
			$weighted_conf += $response->get_confidence() * $chars;
		}

		$merged_gutenberg = $this->merge_gutenberg( $gutenberg_parts );
		$merged_markdown  = implode( "\n\n", array_filter( $markdown_parts ) );
		$merged_plain     = implode( "\n\n", array_filter( $plain_parts ) );
		$avg_confidence   = $total_chars > 0 ? $weighted_conf / $total_chars : 0.0;

		return new OCR_Response(
			true,
			$merged_plain,
			$merged_markdown,
			$merged_gutenberg,
			round( $avg_confidence, 4 ),
			$responses[0]->get_provider(),
			$total_cost,
			array( 'merged_groups' => count( $responses ) )
		);
	}

	/**
	 * Concatenate Gutenberg block HTML segments, deduplicating blocks that
	 * appear at both the end of one segment and the start of the next.
	 *
	 * @param string[] $parts Ordered Gutenberg HTML segments.
	 * @return string
	 */
	private function merge_gutenberg( array $parts ): string {
		$parts = array_filter( $parts );
		if ( empty( $parts ) ) {
			return '';
		}
		if ( count( $parts ) === 1 ) {
			return $parts[0];
		}

		$merged = array_shift( $parts );

		foreach ( $parts as $next ) {
			$overlap = $this->find_overlap( $merged, $next );
			if ( $overlap > 0 ) {
				$next = substr( $next, $overlap );
			}
			$merged = rtrim( $merged ) . "\n\n" . ltrim( $next );
		}

		return trim( $merged );
	}

	/**
	 * Find the length of overlapping Gutenberg block content between the
	 * tail of $a and the head of $b.
	 *
	 * Detects when the last complete block in $a is identical to the first
	 * complete block in $b (duplicate at the boundary).
	 *
	 * @param string $a Trailing segment.
	 * @param string $b Leading segment.
	 * @return int Number of characters to skip from the start of $b.
	 */
	private function find_overlap( string $a, string $b ): int {
		$last_block = $this->extract_last_block( $a );
		if ( empty( $last_block ) ) {
			return 0;
		}

		$first_block = $this->extract_first_block( $b );
		if ( empty( $first_block ) ) {
			return 0;
		}

		// Normalize whitespace for comparison.
		$norm_last  = preg_replace( '/\s+/', ' ', trim( $last_block ) );
		$norm_first = preg_replace( '/\s+/', ' ', trim( $first_block ) );

		if ( $norm_last === $norm_first ) {
			return strlen( $first_block );
		}

		return 0;
	}

	/**
	 * Extract the last complete Gutenberg block comment pair from HTML.
	 *
	 * @param string $html Gutenberg block HTML.
	 * @return string
	 */
	private function extract_last_block( string $html ): string {
		// Match last <!-- wp:... -->...<!-- /wp:... --> pattern.
		if ( preg_match( '/<!-- wp:\S+.*?-->.*?<!-- \/wp:\S+ -->\s*$/s', $html, $m ) ) {
			return $m[0];
		}
		return '';
	}

	/**
	 * Extract the first complete Gutenberg block comment pair from HTML.
	 *
	 * @param string $html Gutenberg block HTML.
	 * @return string
	 */
	private function extract_first_block( string $html ): string {
		if ( preg_match( '/^\s*(<!-- wp:\S+.*?-->.*?<!-- \/wp:\S+ -->)/s', $html, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
