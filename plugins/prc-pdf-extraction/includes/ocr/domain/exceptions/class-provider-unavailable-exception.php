<?php
/**
 * Provider Unavailable Exception
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions;

/**
 * Exception thrown when an OCR provider is unavailable
 */
class Provider_Unavailable_Exception extends OCR_Exception {
	/**
	 * Constructor
	 *
	 * @param string          $message Error message.
	 * @param int             $code Error code.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'OCR provider is unavailable',
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'provider_unavailable', $code, $previous );
	}
}
