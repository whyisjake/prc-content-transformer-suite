<?php
/**
 * Rate Limit Exception
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions;

/**
 * Exception thrown when rate limit is exceeded (429)
 */
class Rate_Limit_Exception extends OCR_Exception {
	/**
	 * Constructor
	 *
	 * @param string          $message Error message.
	 * @param int             $code Error code.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'Rate limit exceeded',
		int $code = 429,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'rate_limit_exceeded', $code, $previous );
	}
}
