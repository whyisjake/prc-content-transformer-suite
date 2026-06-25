<?php
/**
 * Authentication Exception
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions;

/**
 * Exception thrown when authentication fails (401, 403)
 */
class Authentication_Exception extends OCR_Exception {
	/**
	 * Constructor
	 *
	 * @param string          $message Error message.
	 * @param int             $code Error code.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'Authentication failed',
		int $code = 401,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'authentication_failed', $code, $previous );
	}
}
