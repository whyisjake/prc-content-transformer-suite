<?php
/**
 * Quality Validator
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Application;

use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Validation_Failed_Exception;

/**
 * Validates OCR extraction quality
 */
class Quality_Validator {
	/**
	 * Minimum character count threshold
	 *
	 * @var int
	 */
	private $min_characters;

	/**
	 * Minimum confidence score threshold
	 *
	 * @var float
	 */
	private $min_confidence;

	/**
	 * Constructor
	 *
	 * @param int   $min_characters Minimum character count (default: 100).
	 * @param float $min_confidence Minimum confidence score (default: 0.7).
	 */
	public function __construct( int $min_characters = 100, float $min_confidence = 0.7 ) {
		$this->min_characters = apply_filters( 'prc_pdf_extraction_min_chars', $min_characters );
		$this->min_confidence = apply_filters( 'prc_pdf_extraction_min_confidence', $min_confidence );
	}

	/**
	 * Validate an OCR response
	 *
	 * @param OCR_Response $response Response to validate.
	 * @return array Array with 'valid' (bool) and 'issues' (array of strings).
	 */
	public function validate( OCR_Response $response ): array {
		$issues = array();

		// Check success status
		if ( ! $response->is_success() ) {
			$issues[] = 'Extraction was not successful';
		}

		// Check character count
		$char_count = $response->get_character_count();
		if ( $char_count < $this->min_characters ) {
			$issues[] = sprintf(
				'Character count (%d) is below minimum threshold (%d)',
				$char_count,
				$this->min_characters
			);
		}

		// Check confidence score
		$confidence = $response->get_confidence();
		if ( $confidence < $this->min_confidence ) {
			$issues[] = sprintf(
				'Confidence score (%.2f) is below minimum threshold (%.2f)',
				$confidence,
				$this->min_confidence
			);
		}

		// Check for topline patterns
		$text = $response->get_text();
		if ( ! $this->has_topline_patterns( $text ) ) {
			$issues[] = 'Text does not contain expected topline patterns (percentages, n=, Q#, years)';
		}

		// Check UTF-8 encoding
		if ( ! $this->is_valid_utf8( $text ) ) {
			$issues[] = 'Text contains invalid UTF-8 encoding';
		}

		return array(
			'valid'  => empty( $issues ),
			'issues' => $issues,
		);
	}

	/**
	 * Validate and throw exception if invalid
	 *
	 * @param OCR_Response $response Response to validate.
	 * @throws Validation_Failed_Exception If validation fails.
	 * @return void
	 */
	public function validate_or_throw( OCR_Response $response ): void {
		$result = $this->validate( $response );

		if ( ! $result['valid'] ) {
			throw new Validation_Failed_Exception(
				'OCR validation failed: ' . implode( '; ', $result['issues'] )
			);
		}
	}

	/**
	 * Check if text contains topline patterns
	 *
	 * @param string $text Text to check.
	 * @return bool True if contains at least 2 topline patterns.
	 */
	private function has_topline_patterns( string $text ): bool {
		$patterns_found = 0;

		// Check for percentages (e.g., 45%, 12.5%)
		if ( preg_match( '/\d+(\.\d+)?%/', $text ) ) {
			++$patterns_found;
		}

		// Check for sample size notation (e.g., n=1,234, N=500)
		if ( preg_match( '/[nN]\s*=\s*[\d,]+/', $text ) ) {
			++$patterns_found;
		}

		// Check for question numbers (e.g., Q1, Q10, Q.1)
		if ( preg_match( '/Q\.?\d+/', $text ) ) {
			++$patterns_found;
		}

		// Check for years (e.g., 2024, 2023-2024)
		if ( preg_match( '/\b20\d{2}\b/', $text ) ) {
			++$patterns_found;
		}

		// Need at least 2 patterns to be confident
		return $patterns_found >= 2;
	}

	/**
	 * Check if text is valid UTF-8
	 *
	 * @param string $text Text to check.
	 * @return bool True if valid UTF-8.
	 */
	private function is_valid_utf8( string $text ): bool {
		return mb_check_encoding( $text, 'UTF-8' );
	}
}
