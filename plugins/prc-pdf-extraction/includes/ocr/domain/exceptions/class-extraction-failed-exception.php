<?php
/**
 * Extraction Failed Exception
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions;

/**
 * Exception thrown when OCR extraction fails
 */
class Extraction_Failed_Exception extends OCR_Exception {
	/**
	 * Constructor
	 *
	 * @param string          $message Error message.
	 * @param int             $code Error code.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'OCR extraction failed',
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'extraction_failed', $code, $previous );
	}
}
