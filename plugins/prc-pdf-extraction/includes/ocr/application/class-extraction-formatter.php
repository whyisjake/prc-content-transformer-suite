<?php
/**
 * Extraction Formatter
 *
 * Parses OCR text from survey-style PDFs into structured markdown.
 * Uses regex patterns to detect survey questions, response options, and percentages.
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Application;

/**
 * Formats OCR text from survey PDFs into structured markdown
 */
class Extraction_Formatter {

	/**
	 * Question pattern regex
	 * Matches patterns like: Q1., Q.1, Q1a., ASK ALL:, ASK IF, RANDOMIZE, etc.
	 *
	 * @var string
	 */
	const QUESTION_PATTERN = '/^(Q\.?\d+[a-z]?\.?|ASK\s+(ALL|IF|FORM)|RANDOMIZE|DISPLAY|SKIP\s+TO|NO\s+QUESTION|TREND|COMBINED)/i';

	/**
	 * Percentage pattern regex
	 * Matches patterns like: 45%, 12.5%, <1%, *
	 *
	 * @var string
	 */
	const PERCENTAGE_PATTERN = '/(\d+(?:\.\d+)?%|<1%|\*|--)/';

	/**
	 * Sample size pattern regex
	 * Matches patterns like: n=1,234, (n=500), N=1000
	 *
	 * @var string
	 */
	const SAMPLE_SIZE_PATTERN = '/[nN]\s*=\s*[\d,]+/';

	/**
	 * Year pattern regex
	 * Matches patterns like: 2024, Jan 2024, January 2024
	 *
	 * @var string
	 */
	const YEAR_PATTERN = '/(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)?\s*20\d{2}/i';

	/**
	 * Format OCR text into extraction markdown
	 *
	 * @param string $text Raw OCR text.
	 * @return string Formatted markdown.
	 */
	public function format( string $text ): string {
		// Split into lines and process
		$lines = explode( "\n", $text );
		$result = array();

		$current_question = null;
		$current_responses = array();
		$in_question = false;
		$question_text = '';

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( empty( $trimmed ) ) {
				// Empty line - might signal end of question block
				if ( $in_question && ! empty( $current_responses ) ) {
					$result[] = $this->format_question_block( $current_question, $question_text, $current_responses );
					$current_question = null;
					$current_responses = array();
					$question_text = '';
					$in_question = false;
				}
				continue;
			}

			// Check for new question
			if ( preg_match( self::QUESTION_PATTERN, $trimmed, $matches ) ) {
				// Save previous question if exists
				if ( $in_question && ! empty( $current_question ) ) {
					$result[] = $this->format_question_block( $current_question, $question_text, $current_responses );
				}

				// Start new question
				$current_question = $matches[0];
				$question_text = trim( substr( $trimmed, strlen( $matches[0] ) ) );
				$current_responses = array();
				$in_question = true;
				continue;
			}

			// Check if line contains response data (percentages)
			if ( $in_question && preg_match( self::PERCENTAGE_PATTERN, $trimmed ) ) {
				$response = $this->parse_response_line( $trimmed );
				if ( $response ) {
					$current_responses[] = $response;
				}
				continue;
			}

			// Check for continuation of question text
			if ( $in_question && empty( $current_responses ) ) {
				$question_text .= ' ' . $trimmed;
				continue;
			}

			// Otherwise, add to result as-is (header info, methodology notes, etc.)
			if ( ! $in_question ) {
				$result[] = $trimmed;
			}
		}

		// Don't forget the last question
		if ( $in_question && ! empty( $current_question ) ) {
			$result[] = $this->format_question_block( $current_question, $question_text, $current_responses );
		}

		return implode( "\n\n", array_filter( $result ) );
	}

	/**
	 * Parse a response line into structured data
	 *
	 * @param string $line Response line with percentages.
	 * @return array|null Array with 'label' and 'values', or null if can't parse.
	 */
	private function parse_response_line( string $line ): ?array {
		// Extract all percentages/values from the line
		preg_match_all( self::PERCENTAGE_PATTERN, $line, $matches, PREG_OFFSET_MATCH );

		if ( empty( $matches[0] ) ) {
			return null;
		}

		// Get the label (text before first percentage)
		$first_match_pos = $matches[0][0][1];
		$label = trim( substr( $line, 0, $first_match_pos ) );

		// Get all the values
		$values = array_map(
			function( $match ) {
				return $match[0];
			},
			$matches[0]
		);

		// Clean up label - remove leading numbers if they're row numbers
		$label = preg_replace( '/^\d+\s+/', '', $label );

		if ( empty( $label ) && ! empty( $values ) ) {
			return null; // Skip lines that are just numbers
		}

		return array(
			'label'  => $label,
			'values' => $values,
		);
	}

	/**
	 * Format a complete question block as markdown
	 *
	 * @param string $question_id Question identifier (Q1, Q2a, etc.).
	 * @param string $question_text Full question text.
	 * @param array  $responses Array of response data.
	 * @return string Formatted markdown.
	 */
	private function format_question_block( string $question_id, string $question_text, array $responses ): string {
		$output = array();

		// Question header
		$output[] = "### {$question_id}";

		// Question text
		if ( ! empty( $question_text ) ) {
			$output[] = trim( $question_text );
		}

		// Response table
		if ( ! empty( $responses ) ) {
			$output[] = '';

			// Determine column count from first response with multiple values
			$max_cols = 1;
			foreach ( $responses as $response ) {
				$max_cols = max( $max_cols, count( $response['values'] ) );
			}

			// Build table header
			$headers = array( 'Response' );
			for ( $i = 1; $i <= $max_cols; $i++ ) {
				$headers[] = $max_cols > 1 ? "Col {$i}" : '%';
			}
			$output[] = '| ' . implode( ' | ', $headers ) . ' |';
			$output[] = '| ' . implode( ' | ', array_fill( 0, count( $headers ), '---' ) ) . ' |';

			// Build table rows
			foreach ( $responses as $response ) {
				$row = array( $this->escape_markdown( $response['label'] ) );
				$values = $response['values'];

				// Pad values to match column count
				while ( count( $values ) < $max_cols ) {
					$values[] = '';
				}

				$row = array_merge( $row, $values );
				$output[] = '| ' . implode( ' | ', $row ) . ' |';
			}
		}

		return implode( "\n", $output );
	}

	/**
	 * Escape markdown special characters
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private function escape_markdown( string $text ): string {
		return str_replace( '|', '\\|', $text );
	}

	/**
	 * Extract metadata from extraction text
	 *
	 * @param string $text OCR text.
	 * @return array Extracted metadata.
	 */
	public function extract_metadata( string $text ): array {
		$metadata = array(
			'sample_sizes' => array(),
			'dates'        => array(),
			'questions'    => array(),
		);

		// Extract sample sizes
		preg_match_all( self::SAMPLE_SIZE_PATTERN, $text, $matches );
		$metadata['sample_sizes'] = array_unique( $matches[0] );

		// Extract dates/years
		preg_match_all( self::YEAR_PATTERN, $text, $matches );
		$metadata['dates'] = array_unique( $matches[0] );

		// Count questions
		preg_match_all( '/Q\.?\d+[a-z]?/i', $text, $matches );
		$metadata['questions'] = array_unique( $matches[0] );

		return $metadata;
	}
}
