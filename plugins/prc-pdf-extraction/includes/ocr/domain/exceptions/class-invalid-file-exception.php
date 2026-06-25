<?php
/**
 * Invalid File Exception
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions;

/**
 * Exception thrown when the file is invalid or not a PDF
 */
class Invalid_File_Exception extends OCR_Exception {
	/**
	 * Constructor
	 *
	 * @param string          $message Error message.
	 * @param int             $code Error code.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'Invalid file',
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'invalid_file', $code, $previous );
	}
}
