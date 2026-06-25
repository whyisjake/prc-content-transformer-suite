<?php
/**
 * Validation Failed Exception
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions;

/**
 * Exception thrown when validation fails
 */
class Validation_Failed_Exception extends OCR_Exception {
	/**
	 * Constructor
	 *
	 * @param string          $message Error message.
	 * @param int             $code Error code.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'Validation failed',
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'validation_failed', $code, $previous );
	}
}
